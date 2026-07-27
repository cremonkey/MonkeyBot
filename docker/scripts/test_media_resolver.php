<?php
/**
 * SPEC-31 — resolver + room-pick suite. Runs the REAL helpers against the REAL
 * config (read straight from MySQL, no CI bootstrap, no auth).
 *   docker exec monkeybot-app-1 php /var/www/html/docker/scripts/test_media_resolver.php
 */
define('BASEPATH', true);

$db = file_get_contents(__DIR__ . '/../../application/config/database.php');
preg_match("/\['hostname'\]\s*=\s*'([^']*)'/", $db, $h);
preg_match("/\['username'\]\s*=\s*'([^']*)'/", $db, $u);
preg_match("/\['password'\]\s*=\s*'([^']*)'/", $db, $p);
preg_match("/\['database'\]\s*=\s*'([^']*)'/", $db, $d);
$m = new mysqli($h[1], $u[1], $p[1], $d[1]);
$m->set_charset('utf8mb4');
$row = $m->query("SELECT config_json FROM pricing_config WHERE user_id=2 AND status='1'")->fetch_assoc();
$cfg = json_decode($row['config_json'], true);

require_once __DIR__ . '/../../application/helpers/media_helper.php';
require_once __DIR__ . '/../../application/helpers/pricing_helper.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    $ok ? $pass++ : $fail++;
    printf("%s %-46s got=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($got, true));
}
$name = function ($hit) { return $hit === null ? null : $hit['name']; };

echo "--- media_find\n";
check('canonical exact',        $name(media_find($cfg, 'Family Suite')), 'Family Suite');
check('old bot name via alias', $name(media_find($cfg, 'Royal Suite Pool View')), 'Family Suite');
check('arabic alias',           $name(media_find($cfg, 'رويال سويت')), 'Family Suite');
check('colloquial garden',      $name(media_find($cfg, 'دبل جاردن')), 'Deluxe Double or Twin Room');
check('colloquial pool',        $name(media_find($cfg, 'دبل بول')), 'Deluxe Double Room with Balcony');
check('balcony word',           $name(media_find($cfg, 'البلكونة')), 'Deluxe Double Room with Balcony');
check('king',                   $name(media_find($cfg, 'كينج')), 'King Room with Garden View');
check('tashkeel + ة',           $name(media_find($cfg, 'غرفة ثلاثية')), 'Standard Triple Room');
check('day use',                $name(media_find($cfg, 'داي يوز')), 'Day Use');
check('unknown item',           $name(media_find($cfg, 'الفيلا')), null);
check('ambiguous "سويت"',       $name(media_find($cfg, 'سويت')), null);
check('empty',                  $name(media_find($cfg, '   ')), null);

echo "--- specificity: a longer name the customer said beats a shared fragment\n";
check('الدبل بول فيو',   $name(media_find($cfg, 'الدبل بول فيو')), 'Deluxe Double Room with Balcony');
check('صور دبل جاردن فيو', $name(media_find($cfg, 'صور دبل جاردن فيو')), 'Deluxe Double or Twin Room');
// "بول فيو" is an explicit alias of the 5,500 double — that is how the owner
// talks about it ("الجاردن 4500 والبول 5500"), so it resolves rather than asks.
check('bare "بول فيو" -> the 5,500 double', $name(media_find($cfg, 'بول فيو')), 'Deluxe Double Room with Balcony');
check('shared word alone stays ambiguous', $name(media_find($cfg, 'جناح')), null);
check('جونيور سويت',     $name(media_find($cfg, 'الجونيور سويت')), 'Junior Suite with Pool View');

echo "--- markdown links are unwrapped (channels render them raw)\n";
check('generic label dropped',
    media_unwrap_markdown_links('اتفضل الصور 😊 [هنا](https://x.test/a) تحب؟'),
    'اتفضل الصور 😊 https://x.test/a تحب؟');
check('meaningful label kept',
    media_unwrap_markdown_links('[صور الجناح](https://x.test/b)'),
    'صور الجناح: https://x.test/b');
check('plain text untouched', media_unwrap_markdown_links('مفيش لينك هنا'), 'مفيش لينك هنا');
check('bold markers stripped',
    media_unwrap_markdown_links('المناسب **Standard Triple Room** بـ**5,650 جنيه**'),
    'المناسب Standard Triple Room بـ5,650 جنيه');
check('lone asterisks kept', media_unwrap_markdown_links('السعر 5*4'), 'السعر 5*4');

echo "--- albums are distinct and complete\n";
$urls = media_all_urls($cfg);
check('8 albums', count($urls), 8);
check('all unique', count(array_unique($urls)), 8);

echo "--- link gate\n";
check('own album allowed',   media_url_allowed('https://kemzo.byootbayeg.com/en/rooms/family-suite', $urls), true);
check('trailing slash ok',   media_url_allowed('https://kemzo.byootbayeg.com/en/rooms/family-suite/', $urls), true);
check('other room rejected', media_url_allowed('https://kemzo.byootbayeg.com/en/rooms/made-up', $urls), false);
check('foreign host',        media_url_allowed('https://evil.example/x', $urls), false);
$ex = media_extract_urls('اتفضل الصور: https://kemzo.byootbayeg.com/en/rooms/family-suite. تحب أحجزلك؟');
check('url extracted clean', $ex[0], 'https://kemzo.byootbayeg.com/en/rooms/family-suite');

echo "--- pricing picks the right room (the prefix trap)\n";
$r = pricing_calc_accommodation($cfg, 'دبل بول', 1, 2, 0, 0, 0);
check('balcony room 5500', $r['total'], 5500);
$r = pricing_calc_accommodation($cfg, 'دبل جاردن', 1, 2, 0, 0, 0);
check('garden room 4500', $r['total'], 4500);
$r = pricing_calc_accommodation($cfg, 'Deluxe Double Room with Balcony', 1, 2, 0, 0, 0);
check('exact balcony name', $r['total'], 5500);
$r = pricing_calc_accommodation($cfg, 'كينج', 4, 2, 0, 0, 0);
check('king 4-night offer', $r['total'], 11500);
$r = pricing_calc_accommodation($cfg, 'سنجل', 1, 1, 0, 0, 0);
check('single now 3510', $r['total'], 3510);
$r = pricing_calc_accommodation($cfg, 'Royal Suite', 1, 4, 0, 0, 0);
check('old royal name -> family', $r['item'], 'Family Suite');
$r = pricing_calc_accommodation($cfg, '', 1, 3, 0, 0, 0);
check('3 heads -> triple', $r['item'], 'Standard Triple Room');
$r = pricing_calc_accommodation($cfg, 'دبل جاردن', 1, 4, 0, 0, 0);
check('over capacity bumps up', $r['item'], 'Junior Suite with Pool View');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
