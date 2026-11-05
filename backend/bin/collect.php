<?php
// Cron: */20 * * * * /usr/bin/php /path/to/backend/bin/collect.php >> /path/to/collect.log 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once dirname(__DIR__) . '/lib/collector.php';

$db = bhw_db();
$ok = 0;
foreach (bhw_config()['feeds'] as $feed) {
    $xml = bhw_fetch($feed['url']);
    $items = $xml === null ? [] : bhw_parse_feed($xml);
    if (!$items) {
        echo date('c'), " FAILED {$feed['name']}: no items\n";
        continue;
    }
    $ok++;
    $tally = [];
    foreach ($items as $it) {
        $r = bhw_ingest($db, $it, $feed['name']);
        $tally[$r] = ($tally[$r] ?? 0) + 1;
    }
    echo date('c'), " {$feed['name']}: ", json_encode($tally), "\n";
}
// The app warns readers when this date goes stale, so it only moves on success.
if ($ok > 0) {
    bhw_meta_set($db, 'last_collect', date('Y-m-d'));
}
exit($ok > 0 ? 0 : 1);
