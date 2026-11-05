<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/classify.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const CATEGORIES = ['dengue', 'respiratory', 'leptospirosis', 'vaccination', 'bites', 'air', 'products', 'services', 'hiv', 'other'];
const ALERTS = ['', 'active', 'watch', 'unknown', 'ended'];
const SCOPES = ['municipal', 'province', 'region', 'national'];

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function pick(string $v, array $allowed, string $fallback): string
{
    return in_array($v, $allowed, true) ? $v : $fallback;
}
function towns_from(string $csv): array
{
    $out = [];
    foreach (explode(',', $csv) as $t) {
        $t = trim($t);
        if (in_array($t, BHW_MUNICIPALITIES, true)) {
            $out[] = $t;
        }
    }
    return array_values(array_unique($out));
}
function page(string $body): void
{
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
    '<title>Bohol Health Watch admin</title><style>body{font:16px/1.45 system-ui,sans-serif;margin:0 auto;max-width:640px;padding:16px;color:#0d2238}',
    'h1,h2{line-height:1.15}label{display:block;font-weight:600;margin-top:10px}input,select,textarea{width:100%;box-sizing:border-box;font:inherit;padding:10px;border:1px solid #9db8d4;border-radius:6px}',
    'input[type=checkbox]{width:auto}button{font:inherit;font-weight:700;min-height:44px;padding:0 16px;border:0;border-radius:6px;background:#0a56b0;color:#fff;margin:12px 8px 0 0}',
    'button.alt{background:#e9eef3;color:#0d2238}article{border-top:1px solid #d5e0ec;padding:14px 0}.err{color:#b3261e;font-weight:700}.ok{color:#17694a;font-weight:700}small{color:#50657b}</style>',
    $body, '</html>';
}
function fields(array $r): string
{
    $o = fn ($list, $cur) => implode('', array_map(fn ($v) => '<option value="' . h($v) . '"' . ($v === $cur ? ' selected' : '') . '>' . h($v === '' ? 'none' : $v) . '</option>', $list));
    return '<label>Title<input name="title" required maxlength="400" value="' . h($r['title'] ?? '') . '"></label>'
        . '<label>Summary<textarea name="summary" rows="3" required>' . h($r['summary'] ?? '') . '</textarea></label>'
        . '<label>Topic<select name="category">' . $o(CATEGORIES, $r['category'] ?? 'other') . '</select></label>'
        . '<label>Source trust<select name="trust">' . $o(['news', 'official'], $r['trust'] ?? 'news') . '</select></label>'
        . '<label>Alert status<select name="alert">' . $o(ALERTS, (string) ($r['alert'] ?? '')) . '</select></label>'
        . '<label>Alert note<input name="alert_note" maxlength="400" value="' . h($r['alert_note'] ?? '') . '"></label>'
        . '<label>Area<select name="scope">' . $o(SCOPES, $r['scope'] ?? 'province') . '</select></label>'
        . '<label>Towns named, separated by commas<input name="towns" value="' . h(implode(', ', json_decode($r['municipalities'] ?? '[]', true) ?: [])) . '"></label>'
        . '<label><input type="checkbox" name="notify" value="1"' . (!empty($r['notify']) ? ' checked' : '') . '> Send a phone notification</label>';
}

$hash = bhw_config()['admin_password_hash'] ?? '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    usleep(400000);
    if ($hash !== '' && hash_equals(csrf(), (string) ($_POST['csrf'] ?? '')) && password_verify((string) ($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $msg = 'That password is not correct.';
}
if (empty($_SESSION['admin'])) {
    page('<h1>Bohol Health Watch admin</h1>' . ($hash === '' ? '<p class="err">Set admin_password_hash in config.php first.</p>' : '')
        . ($msg ? '<p class="err">' . h($msg) . '</p>' : '')
        . '<form method="post"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="action" value="login">'
        . '<label>Password<input type="password" name="password" required autofocus></label><button>Sign in</button></form>');
    exit;
}

$db = bhw_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Your session expired. Reload the page and try again.');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'logout') {
        session_destroy();
        header('Location: admin.php');
        exit;
    }
    $values = [
        trim((string) ($_POST['title'] ?? '')), trim((string) ($_POST['summary'] ?? '')),
        pick((string) ($_POST['category'] ?? ''), CATEGORIES, 'other'), pick((string) ($_POST['trust'] ?? ''), ['news', 'official'], 'news'),
        ($a = pick((string) ($_POST['alert'] ?? ''), ALERTS, '')) === '' ? null : $a, trim((string) ($_POST['alert_note'] ?? '')) ?: null,
        pick((string) ($_POST['scope'] ?? ''), SCOPES, 'province'), json_encode(towns_from((string) ($_POST['towns'] ?? '')), JSON_UNESCAPED_UNICODE),
        empty($_POST['notify']) ? 0 : 1,
    ];
    $seq = (int) ($_POST['seq'] ?? 0);
    if ($action === 'publish' || $action === 'save') {
        $st = $db->prepare('SELECT state, notify, pub_seq FROM reports WHERE seq = ?');
        $st->execute([$seq]);
        $old = $st->fetch();
        if ($old) {
            // Newly published, or newly flagged for notification: give it a fresh number so phones pick it up.
            $fresh = $old['state'] !== 'published' || ($values[8] === 1 && (int) $old['notify'] === 0);
            $pubSeq = $fresh ? bhw_next_pub_seq($db) : (int) $old['pub_seq'];
            $db->prepare("UPDATE reports SET title=?, summary=?, category=?, trust=?, alert=?, alert_note=?, scope=?, municipalities=?, notify=?, pub_seq=?, state='published' WHERE seq=?")
                ->execute([...$values, $pubSeq, $seq]);
        }
        $msg = 'Published.';
    } elseif ($action === 'reject') {
        $db->prepare("UPDATE reports SET state='rejected', notify=0 WHERE seq=?")->execute([$seq]);
        $msg = 'Rejected. It will not appear in the app.';
    } elseif ($action === 'add') {
        // A post URL pasted by hand, such as an official Facebook page post. The app does not scrape Facebook.
        $url = trim((string) ($_POST['source_url'] ?? ''));
        $name = trim((string) ($_POST['source_name'] ?? ''));
        $date = (string) ($_POST['pub_date'] ?? '');
        if (!bhw_is_http_url($url) || $name === '' || $values[0] === '' || $values[1] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $msg = 'Fill in the title, summary, date, source name and a full source URL.';
        } else {
            $h = hash('sha256', strtolower(rtrim($url, '/')));
            try {
                $db->prepare("INSERT INTO reports (slug, title, title_norm, summary, facts, pub_date, category, trust, alert, alert_note, highlight_note, scope, municipalities, source_name, source_url, url_hash, state, pub_seq, serious, notify, origin, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'published',?,0,?,'admin',?)")->execute([
                    'manual-' . substr($h, 0, 16), $values[0], bhw_norm_title($values[0]), $values[1], '[]', $date, $values[2], $values[3], $values[4], $values[5], null,
                    $values[6], $values[7], $name, $url, $h, bhw_next_pub_seq($db), $values[8], date('c'),
                ]);
                $msg = 'Added and published.';
            } catch (PDOException $e) {
                $msg = 'That source URL is already stored.';
            }
        }
    }
}

$pending = $db->query("SELECT * FROM reports WHERE state='pending' ORDER BY pub_date DESC, seq DESC")->fetchAll();
$recent = $db->query("SELECT * FROM reports WHERE state='published' ORDER BY pub_date DESC, seq DESC LIMIT 30")->fetchAll();
$token = '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
$out = '<h1>Bohol Health Watch admin</h1>' . ($msg ? '<p class="ok">' . h($msg) . '</p>' : '');
$out .= '<h2>Waiting for review (' . count($pending) . ')</h2><p><small>Serious reports stay hidden until you publish them. Check the source first. Mark a report Official only if a government body made the statement.</small></p>';
foreach ($pending as $r) {
    $out .= '<article><form method="post">' . $token . '<input type="hidden" name="seq" value="' . (int) $r['seq'] . '">'
        . '<small>' . h($r['pub_date']) . ' · ' . h($r['source_name']) . ' · <a href="' . h($r['source_url']) . '" target="_blank" rel="noopener">Open source</a></small>'
        . fields($r) . '<button name="action" value="publish">Publish</button><button class="alt" name="action" value="reject">Reject</button></form></article>';
}
if (!$pending) {
    $out .= '<p>Nothing is waiting.</p>';
}
$out .= '<h2>Add a report by hand</h2><p><small>Use this for posts on official Facebook pages.</small></p><form method="post">' . $token
    . fields([]) . '<label>Date<input type="date" name="pub_date" required value="' . date('Y-m-d') . '"></label>'
    . '<label>Source name<input name="source_name" required maxlength="120"></label><label>Source URL<input type="url" name="source_url" required></label>'
    . '<button name="action" value="add">Add and publish</button></form>';
$out .= '<h2>Published</h2>';
foreach ($recent as $r) {
    $out .= '<article><details><summary>' . h($r['pub_date']) . ' · ' . h($r['title']) . '</summary><form method="post">' . $token
        . '<input type="hidden" name="seq" value="' . (int) $r['seq'] . '">' . fields($r)
        . '<button name="action" value="save">Save</button><button class="alt" name="action" value="reject">Remove from app</button></form></details></article>';
}
$out .= '<form method="post">' . $token . '<button class="alt" name="action" value="logout">Sign out</button></form>';
page($out);
