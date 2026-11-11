<?php
// Used by .github/workflows/update-news.yml. Collects news and writes live/feed.json.
// data/seed.json is the human-edited source: figures, Official labels and alert status come only from there.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once dirname(__DIR__) . '/lib/collector.php';

$root = dirname(__DIR__, 2);
if (!is_dir("$root/live")) {
    mkdir("$root/live");
}
$db = bhw_db();
bhw_install($db);
$seed = json_decode((string) file_get_contents("$root/data/seed.json"), true, 32, JSON_THROW_ON_ERROR);

foreach ($seed['items'] as $i) {
    $st = $db->prepare('SELECT seq, alert FROM reports WHERE slug = ?');
    $st->execute([$i['id']]);
    $old = $st->fetch();
    $seq = $old ? $old['seq'] : false;
    $values = [
        $i['title'], bhw_norm_title($i['title']), $i['summary'], json_encode($i['facts'], JSON_UNESCAPED_UNICODE), $i['date'],
        $i['category'], $i['trust'], $i['alert'], $i['alertNote'] ?? null, $i['highlightNote'] ?? null, $i['scope'],
        json_encode($i['municipalities'], JSON_UNESCAPED_UNICODE), $i['source']['name'], $i['source']['url'], $i['image'] ?? null, $i['tone'] ?? null,
    ];
    if ($seq) {
        $db->prepare('UPDATE reports SET title=?, title_norm=?, summary=?, facts=?, pub_date=?, category=?, trust=?, alert=?, alert_note=?,
            highlight_note=?, scope=?, municipalities=?, source_name=?, source_url=?, image_url=?, tone=? WHERE seq=?')->execute([...$values, $seq]);
        // A report that just became an alert counts as new, so phones are told.
        if (in_array($i['alert'], ['active', 'watch'], true) && $old['alert'] !== $i['alert']) {
            $db->prepare('UPDATE reports SET pub_seq = ?, notify = 1 WHERE seq = ?')->execute([bhw_next_pub_seq($db), $seq]);
        }
    } else {
        $hash = hash('sha256', strtolower(rtrim($i['source']['url'], '/')) . '#' . $i['id']);
        $db->prepare("INSERT INTO reports (title, title_norm, summary, facts, pub_date, category, trust, alert, alert_note, highlight_note, scope,
            municipalities, source_name, source_url, image_url, tone, slug, url_hash, state, pub_seq, serious, notify, origin, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'published',?,0,?,'seed',?)")
            ->execute([...$values, $i['id'], $hash, bhw_next_pub_seq($db), empty($i['notify']) ? 0 : 1, date('c')]);
    }
}

// The hand-checked seed report wins when a collected copy of the same article is already stored.
foreach ($seed['items'] as $i) {
    $bare = rtrim($i['source']['url'], '/');
    $db->prepare("DELETE FROM reports WHERE origin = 'feed' AND (source_url = ? OR source_url = ?)")->execute([$bare, $bare . '/']);
}

$ok = 0;
$tried = bhw_config()['feeds'];
foreach ($tried as $feed) {
    $xml = bhw_fetch($feed['url']);
    $items = $xml === null ? [] : bhw_parse_feed($xml);
    if (!$items) {
        echo "FAILED {$feed['name']}: no items\n";
        continue;
    }
    $ok++;
    $tally = [];
    foreach ($items as $it) {
        $r = bhw_ingest($db, $it, $feed['name'], $feed);
        $tally[$r] = ($tally[$r] ?? 0) + 1;
    }
    echo "{$feed['name']}: ", json_encode($tally), "\n";
}
// Facebook Page posts, using a Page access token stored as a GitHub Actions secret.
// Search for FB_ACCESS_TOKEN in GitHub Settings > Secrets and variables > Actions.
// Placeholder only (do not put a real token in this public file): FB_ACCESS_TOKEN=YOUR_PAGE_ACCESS_TOKEN_HERE
$fbToken = getenv('FB_ACCESS_TOKEN') ?: '';
if ($fbToken !== '') {
    foreach (bhw_config()['facebook_pages'] ?? [] as $page) {
        $query = http_build_query(['fields' => 'message,created_time,permalink_url,full_picture', 'limit' => 10, 'access_token' => $fbToken]);
        $body = bhw_fetch('https://graph.facebook.com/v26.0/' . rawurlencode($page['id']) . '/posts?' . $query);
        $posts = $body === null ? null : json_decode($body, true);
        if (!is_array($posts) || !isset($posts['data'])) {
            $err = is_array($posts) ? ($posts['error']['message'] ?? 'invalid response') : 'request failed';
            echo "FAILED Facebook {$page['name']}: {$err}\n";
            continue;
        }
        $tally = [];
        foreach ($posts['data'] as $post) {
            $message = trim((string) ($post['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $r = bhw_ingest($db, [
                'title' => bhw_excerpt($message, 110), 'url' => (string) ($post['permalink_url'] ?? ''), 'html' => $message,
                'date' => (string) ($post['created_time'] ?? ''), 'image' => $post['full_picture'] ?? null,
            ], $page['name'], [
                'trust' => ($page['trust'] ?? 'news') === 'official' ? 'official' : 'news',
                'all_health' => !empty($page['all_health']),
            ]);
            $tally[$r] = ($tally[$r] ?? 0) + 1;
        }
        echo "Facebook {$page['name']}: ", json_encode($tally), "\n";
    }
}
if ($ok > 0) {
    bhw_meta_set($db, 'last_collect', date('Y-m-d'));
}

/** A watch with no follow-up ends by itself. An active alert stays until a person ends it. */
function bhw_expire_alert(array $r): array
{
    $days = $r['origin'] === 'feed' ? 14 : 30;
    if ($r['alert'] === 'watch' && strtotime($r['pub_date']) < strtotime("-$days days")) {
        $r['alert'] = 'ended';
        $r['alert_note'] = "No update for $days days.";
    }
    if ($r['alert'] === 'active' && $r['origin'] === 'feed' && strtotime($r['pub_date']) < strtotime('-30 days')) {
        $r['alert'] = 'unknown';
        $r['alert_note'] = 'No update for 30 days, so check whether this still applies.';
    }
    return $r;
}

$rows = $db->query("SELECT * FROM reports WHERE state = 'published' ORDER BY pub_date DESC, pub_seq DESC LIMIT 200")->fetchAll();
$max = (int) $db->query("SELECT COALESCE(MAX(pub_seq), 0) FROM reports WHERE state = 'published'")->fetchColumn();
$json = json_encode([
    'generated' => bhw_meta_get($db, 'last_collect') ?? $seed['generated'],
    'max_seq' => $max,
    'metrics' => $seed['metrics'] ?? null,
    'items' => array_map('bhw_row_to_item', array_map('bhw_expire_alert', $rows)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents("$root/live/feed.json", $json . "\n");
echo 'Wrote ', count($rows), " reports. $ok of ", count($tried), " feeds worked.\n";
// A red run in the Actions tab means every source failed, while the last good feed stays online.
exit($ok > 0 ? 0 : 1);
