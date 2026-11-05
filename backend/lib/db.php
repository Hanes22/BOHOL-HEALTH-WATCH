<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

function bhw_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = getenv('BHW_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('Missing config.php. Copy config.sample.php to config.php and fill it in.');
        }
        $cfg = require $path;
    }
    return $cfg;
}

function bhw_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = bhw_config();
        $pdo = new PDO($c['dsn'], $c['db_user'] ?? null, $c['db_pass'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function bhw_install(PDO $db): void
{
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pk = $mysql ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS reports (
        seq $pk,
        slug VARCHAR(80) NOT NULL UNIQUE,
        title VARCHAR(400) NOT NULL,
        title_norm VARCHAR(400) NOT NULL,
        summary TEXT NOT NULL,
        facts TEXT NOT NULL,
        pub_date CHAR(10) NOT NULL,
        category VARCHAR(30) NOT NULL,
        trust VARCHAR(10) NOT NULL DEFAULT 'news',
        alert VARCHAR(10) NULL,
        alert_note VARCHAR(400) NULL,
        highlight_note VARCHAR(200) NULL,
        scope VARCHAR(12) NOT NULL,
        municipalities TEXT NOT NULL,
        source_name VARCHAR(120) NOT NULL,
        source_url VARCHAR(700) NOT NULL,
        url_hash CHAR(64) NOT NULL UNIQUE,
        state VARCHAR(10) NOT NULL,
        pub_seq BIGINT NOT NULL DEFAULT 0,
        serious INT NOT NULL DEFAULT 0,
        notify INT NOT NULL DEFAULT 0,
        origin VARCHAR(10) NOT NULL DEFAULT 'feed',
        created_at VARCHAR(25) NOT NULL
    )$tail");
    $db->exec("CREATE TABLE IF NOT EXISTS meta (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    )$tail");
}

function bhw_meta_set(PDO $db, string $k, string $v): void
{
    $db->prepare('DELETE FROM meta WHERE k = ?')->execute([$k]);
    $db->prepare('INSERT INTO meta (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

function bhw_meta_get(PDO $db, string $k): ?string
{
    $st = $db->prepare('SELECT v FROM meta WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/**
 * Phones ask for reports newer than the last pub_seq they saw. A report gets its number when it is
 * published, not when it is collected, so a report approved after review still reaches them.
 */
function bhw_next_pub_seq(PDO $db): int
{
    $next = (int) (bhw_meta_get($db, 'pub_counter') ?? 0) + 1;
    bhw_meta_set($db, 'pub_counter', (string) $next);
    return $next;
}

function bhw_is_http_url(string $url): bool
{
    return (bool) preg_match('#^https?://[^\s]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function bhw_norm_title(string $t): string
{
    $t = mb_strtolower(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
    $t = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $t) ?? $t;
    return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
}

function bhw_row_to_item(array $r): array
{
    $item = [
        'seq' => (int) $r['pub_seq'],
        'id' => $r['slug'],
        'title' => $r['title'],
        'date' => $r['pub_date'],
        'category' => $r['category'],
        'trust' => $r['trust'],
        'alert' => $r['alert'] ?: null,
        'scope' => $r['scope'],
        'municipalities' => json_decode($r['municipalities'], true) ?: [],
        'summary' => $r['summary'],
        'facts' => json_decode($r['facts'], true) ?: [],
        'source' => ['name' => $r['source_name'], 'url' => $r['source_url']],
        'notify' => (bool) $r['notify'],
    ];
    if (!empty($r['alert_note'])) {
        $item['alertNote'] = $r['alert_note'];
    }
    if (!empty($r['highlight_note'])) {
        $item['highlightNote'] = $r['highlight_note'];
    }
    return $item;
}
