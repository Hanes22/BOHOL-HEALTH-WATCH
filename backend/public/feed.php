<?php
// GET feed.php?limit=100&since_seq=0&municipality=Talibon&category=dengue
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=120');
header('X-Content-Type-Options: nosniff');

try {
    $db = bhw_db();
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 100)));
    $since = max(0, (int) ($_GET['since_seq'] ?? 0));
    $sql = "SELECT * FROM reports WHERE state = 'published' AND pub_seq > ?";
    $args = [$since];
    if (isset($_GET['category']) && is_string($_GET['category'])) {
        $sql .= ' AND category = ?';
        $args[] = $_GET['category'];
    }
    $sql .= ' ORDER BY pub_date DESC, pub_seq DESC LIMIT ' . $limit;
    $st = $db->prepare($sql);
    $st->execute($args);
    $items = array_map('bhw_row_to_item', $st->fetchAll());
    if (isset($_GET['municipality']) && is_string($_GET['municipality']) && $_GET['municipality'] !== '') {
        $town = $_GET['municipality'];
        $items = array_values(array_filter(
            $items,
            static fn ($i) => $i['scope'] !== 'municipal' || in_array($town, $i['municipalities'], true)
        ));
    }
    $max = (int) $db->query("SELECT COALESCE(MAX(pub_seq), 0) FROM reports WHERE state = 'published'")->fetchColumn();
    echo json_encode([
        'generated' => bhw_meta_get($db, 'last_collect') ?? date('Y-m-d'),
        'max_seq' => $max,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('feed.php: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Reports are unavailable right now.']);
}
