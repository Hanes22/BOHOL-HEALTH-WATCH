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
    $st = $db->prepare('SELECT seq FROM reports WHERE slug = ?');
    $st->execute([$i['id']]);
    $seq = $st->fetchColumn();
    $values = [
        $i['title'], bhw_norm_title($i['title']), $i['summary'], json_encode($i['facts'], JSON_UNESCAPED_UNICODE), $i['date'],
        $i['category'], $i['trust'], $i['alert'], $i['alertNote'] ?? null, $i['highlightNote'] ?? null, $i['scope'],
        json_encode($i['municipalities'], JSON_UNESCAPED_UNICODE), $i['source']['name'], $i['source']['url'],
    ];
    if ($seq) {
        $db->prepare('UPDATE reports SET title=?, title_norm=?, summary=?, facts=?, pub_date=?, category=?, trust=?, alert=?, alert_note=?,
            highlight_note=?, scope=?, municipalities=?, source_name=?, source_url=? WHERE seq=?')->execute([...$values, $seq]);
    } else {
        $hash = hash('sha256', strtolower(rtrim($i['source']['url'], '/')) . '#' . $i['id']);
        $db->prepare("INSERT INTO reports (title, title_norm, summary, facts, pub_date, category, trust, alert, alert_note, highlight_note, scope,
            municipalities, source_name, source_url, slug, url_hash, state, pub_seq, serious, notify, origin, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'published',?,0,0,'seed',?)")
            ->execute([...$values, $i['id'], $hash, bhw_next_pub_seq($db), date('c')]);
    }
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
if ($ok > 0) {
    bhw_meta_set($db, 'last_collect', date('Y-m-d'));
}

$rows = $db->query("SELECT * FROM reports WHERE state = 'published' ORDER BY pub_date DESC, pub_seq DESC LIMIT 200")->fetchAll();
$max = (int) $db->query("SELECT COALESCE(MAX(pub_seq), 0) FROM reports WHERE state = 'published'")->fetchColumn();
$json = json_encode([
    'generated' => bhw_meta_get($db, 'last_collect') ?? $seed['generated'],
    'max_seq' => $max,
    'metrics' => $seed['metrics'] ?? null,
    'items' => array_map('bhw_row_to_item', $rows),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents("$root/live/feed.json", $json . "\n");
echo 'Wrote ', count($rows), " reports. $ok of ", count($tried), " feeds worked.\n";
// A red run in the Actions tab means every source failed, while the last good feed stays online.
exit($ok > 0 ? 0 : 1);
