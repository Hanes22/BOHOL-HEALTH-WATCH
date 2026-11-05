<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/classify.php';

/** Parses RSS 2.0 or Atom text into items. Returns [] when the text is not a feed. */
function bhw_parse_feed(string $xml): array
{
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($doc === false) {
        return [];
    }
    $out = [];
    if (isset($doc->channel->item)) {
        foreach ($doc->channel->item as $i) {
            $out[] = [
                'title' => trim((string) $i->title),
                'url' => trim((string) $i->link),
                'html' => (string) $i->description,
                'date' => (string) $i->pubDate,
            ];
        }
        return $out;
    }
    $doc->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
    foreach ($doc->xpath('//a:entry') ?: [] as $e) {
        $e->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        $link = $e->xpath('a:link[@rel="alternate"]/@href') ?: $e->xpath('a:link/@href');
        $body = $e->xpath('a:summary') ?: $e->xpath('a:content');
        $when = $e->xpath('a:published') ?: $e->xpath('a:updated');
        $title = $e->xpath('a:title');
        $out[] = [
            'title' => trim((string) ($title[0] ?? '')),
            'url' => trim((string) ($link[0] ?? '')),
            'html' => (string) ($body[0] ?? ''),
            'date' => (string) ($when[0] ?? ''),
        ];
    }
    return $out;
}

function bhw_fetch(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'BoholHealthWatch/1.0 (RSS reader)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($body) && $code >= 200 && $code < 300 ? $body : null;
}

/** True when a similar headline was already stored within seven days of $date. */
function bhw_is_duplicate_title(PDO $db, string $norm, string $date): bool
{
    $from = date('Y-m-d', strtotime($date . ' -7 days'));
    $to = date('Y-m-d', strtotime($date . ' +7 days'));
    $st = $db->prepare('SELECT title_norm FROM reports WHERE pub_date BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $other) {
        similar_text($norm, (string) $other, $pct);
        if ($pct >= 85.0) {
            return true;
        }
    }
    return false;
}

function bhw_slug(string $title, string $hash): string
{
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', bhw_norm_title($title)) ?? '', '-');
    return substr($s, 0, 60) . '-' . substr($hash, 0, 8);
}

/** Stores one feed entry. Returns stored_published, stored_pending, duplicate, not_health or invalid. */
function bhw_ingest(PDO $db, array $it, string $sourceName, array $feed = []): string
{
    $title = html_entity_decode($it['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($title === '' || !bhw_is_http_url($it['url'])) {
        return 'invalid';
    }
    $summary = bhw_excerpt($it['html']);
    $text = $title . ' ' . $summary;
    // Region-wide papers: keep only stories that actually mention Bohol.
    if (!empty($feed['require_bohol']) && !preg_match('/\b(Bohol|Boholano|Tagbilaran|Panglao)\b/iu', $text)) {
        return 'not_bohol';
    }
    $category = bhw_category($text);
    if ($category === null) {
        return 'not_health';
    }
    $hash = hash('sha256', strtolower(rtrim($it['url'], '/')));
    // Also match by address, because a seed report may carry its own wording of the same article.
    $bare = rtrim($it['url'], '/');
    $st = $db->prepare('SELECT 1 FROM reports WHERE url_hash = ? OR source_url = ? OR source_url = ?');
    $st->execute([$hash, $bare, $bare . '/']);
    if ($st->fetchColumn()) {
        return 'duplicate';
    }
    $ts = strtotime($it['date']);
    $date = date('Y-m-d', $ts ?: time());
    $norm = bhw_norm_title($title);
    if (bhw_is_duplicate_title($db, $norm, $date)) {
        return 'duplicate';
    }
    $towns = bhw_municipalities($text);
    $serious = bhw_is_serious($text);
    // With no one reviewing (the GitHub-hosted feed), serious stories publish as unconfirmed News reports.
    // They still can't become Official or an alert without a person editing data/seed.json.
    $auto = !empty(bhw_config()['auto_publish']);
    $hold = $serious && !$auto;
    $state = $hold ? 'pending' : 'published';
    $scope = bhw_scope($text, $towns);
    $notify = $auto && $scope !== 'national' ? 1 : 0;
    $db->prepare('INSERT INTO reports (slug, title, title_norm, summary, facts, pub_date, category, trust, alert, scope,
        municipalities, source_name, source_url, url_hash, state, pub_seq, serious, notify, origin, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        bhw_slug($title, $hash), mb_substr($title, 0, 400, 'UTF-8'), mb_substr($norm, 0, 400, 'UTF-8'),
        $summary !== '' ? $summary : $title, '[]', $date, $category, ($feed['trust'] ?? '') === 'official' ? 'official' : 'news', null, $scope,
        json_encode($towns, JSON_UNESCAPED_UNICODE), $sourceName, $it['url'], $hash, $state, $hold ? 0 : bhw_next_pub_seq($db), $serious ? 1 : 0, $notify,
        'feed', date('c'),
    ]);
    return $hold ? 'stored_pending' : 'stored_published';
}
