<?php
// Creates the tables and loads the verified starting reports from data/seed.json.
// Safe to run again: reports already stored are skipped.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once dirname(__DIR__) . '/lib/db.php';

$db = bhw_db();
bhw_install($db);
$seed = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/seed.json'), true, 32, JSON_THROW_ON_ERROR);
$added = 0;
foreach ($seed['items'] as $i) {
    $hash = hash('sha256', strtolower(rtrim($i['source']['url'], '/')) . '#' . $i['id']);
    $st = $db->prepare('SELECT 1 FROM reports WHERE slug = ?');
    $st->execute([$i['id']]);
    if ($st->fetchColumn()) {
        continue;
    }
    $db->prepare('INSERT INTO reports (slug, title, title_norm, summary, facts, pub_date, category, trust, alert, alert_note,
        highlight_note, scope, municipalities, source_name, source_url, url_hash, state, pub_seq, serious, notify, origin, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $i['id'], $i['title'], bhw_norm_title($i['title']), $i['summary'], json_encode($i['facts'], JSON_UNESCAPED_UNICODE),
        $i['date'], $i['category'], $i['trust'], $i['alert'], $i['alertNote'] ?? null, $i['highlightNote'] ?? null, $i['scope'],
        json_encode($i['municipalities'], JSON_UNESCAPED_UNICODE), $i['source']['name'], $i['source']['url'], $hash,
        'published', bhw_next_pub_seq($db), 0, 0, 'seed', date('c'),
    ]);
    $added++;
}
if (bhw_meta_get($db, 'last_collect') === null) {
    bhw_meta_set($db, 'last_collect', $seed['generated']);
}
echo "Installed. Added $added reports.\n";
