<?php
// php tests/run.php  (uses a throwaway SQLite database)
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/bhw-test-' . getmypid();
mkdir($dir);
$cfg = "$dir/config.php";
file_put_contents($cfg, "<?php return ['dsn'=>'sqlite:$dir/t.sqlite','admin_password_hash'=>'','feeds'=>[]];");
putenv("BHW_CONFIG=$cfg");
require dirname(__DIR__) . '/lib/collector.php';

$fails = 0;
function check(string $name, $got, $want): void
{
    global $fails;
    if ($got === $want) {
        echo "ok   $name\n";
    } else {
        $fails++;
        echo "FAIL $name: got ", json_encode($got), ' want ', json_encode($want), "\n";
    }
}

check('Tagbilaran alone', bhw_municipalities('Rally in Tagbilaran today'), ['Tagbilaran City']);
check('Tagbilaran City', bhw_municipalities('Rally in Tagbilaran City today'), ['Tagbilaran City']);
check('Carmen, Cebu ignored', bhw_municipalities('Meeting in Carmen, Cebu'), []);
check('Danao City ignored', bhw_municipalities('Danao City hospital'), []);
check('Carmen, Bohol found', bhw_municipalities('Meeting in Carmen, Bohol'), ['Carmen']);
check('lowercase words ignored', bhw_municipalities('the anda loon lila ubay'), []);
check('two towns', bhw_municipalities('Ubay and Talibon cases'), ['Talibon', 'Ubay']);
check('crash at hospital is not health news', bhw_category('Rider rushed to hospital after crash in Candijay'), null);
check('AIDS uppercase only', bhw_category('First aids given by teachers'), null);
check('dengue category', bhw_category('Dengue cases rise'), 'dengue');
check('outbreak is serious', bhw_is_serious('Measles outbreak in town'), true);
check('routine is not serious', bhw_is_serious('Vaccination opens on Monday'), false);
check('excerpt strips tags', bhw_excerpt('<p>Hello <b>there</b> &amp; you</p>'), 'Hello there & you');

$db = bhw_db();
bhw_install($db);
$items = bhw_parse_feed((string) file_get_contents(__DIR__ . '/fixtures/rss.xml'));
check('rss items parsed', count($items), 9);
check('entity not expanded', strpos(json_encode($items), 'root:') === false, true);
$res = array_map(fn ($i) => bhw_ingest($db, $i, 'Fixture'), $items);
check('ingest outcomes', $res, [
    'stored_pending', 'not_health', 'not_health', 'stored_published', 'duplicate', 'duplicate', 'stored_published', 'invalid', 'stored_published',
]);
$atom = bhw_parse_feed((string) file_get_contents(__DIR__ . '/fixtures/atom.xml'));
check('atom parsed', $atom[0]['url'] ?? '', 'https://example.com/atom1');
check('atom stored', bhw_ingest($db, $atom[0], 'Fixture'), 'stored_published');
check('html page rejected as feed', bhw_parse_feed('<html><body><p>Not a feed'), []);

$row = $db->query("SELECT * FROM reports WHERE source_url='https://example.com/a'")->fetch();
check('serious stays pending', $row['state'], 'pending');
check('serious town', json_decode($row['municipalities'], true), ['Inabanga']);
check('feed never self-marks official', (int) $db->query("SELECT COUNT(*) FROM reports WHERE trust='official'")->fetchColumn(), 0);
check('feed never sets alerts', (int) $db->query('SELECT COUNT(*) FROM reports WHERE alert IS NOT NULL')->fetchColumn(), 0);
check('feed never notifies by itself', (int) $db->query('SELECT COUNT(*) FROM reports WHERE notify <> 0')->fetchColumn(), 0);
$cebu = $db->query("SELECT scope, municipalities FROM reports WHERE source_url='https://example.com/f'")->fetch();
check('Cebu story not tagged Bohol', [$cebu['scope'], $cebu['municipalities']], ['national', '[]']);
$g = $db->query("SELECT scope, summary FROM reports WHERE source_url='https://example.com/g'")->fetch();
check('province scope', $g['scope'], 'province');

array_map('unlink', glob("$dir/*"));
rmdir($dir);
echo $fails ? "$fails FAILED\n" : "All passed\n";
exit($fails ? 1 : 0);
