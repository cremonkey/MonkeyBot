<?php
/**
 * Phase 11 — dependency-free tests for the industry templates and the wizard's pure logic.
 * No PHPUnit, no CodeIgniter bootstrap, no DB, no network.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/onboarding/run.php
 *
 * What it proves:
 *   - every vertical file in application/data/industry_templates/ satisfies the schema
 *   - the hotel price skeleton passes the REAL pricing_validate_config(), not a copy
 *   - verticals the calculator cannot serve carry no pricing_config at all
 *   - the compiled persona actually contains the template's rules and questions
 *   - applying the starter FAQ twice never inserts twice, and never wipes an owner's answer
 *
 * The DB is faked at the smallest honest seam: industry_template_faq_plan() is the same
 * pure function Onboarding::save_knowledge() executes, so this is the real rule, not a
 * re-implementation of it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

define('BASEPATH', dirname(__DIR__, 2) . '/system/');
define('APPPATH', dirname(__DIR__, 2) . '/application/');
date_default_timezone_set('UTC');

require APPPATH . 'helpers/pricing_helper.php';
require APPPATH . 'helpers/industry_template_helper.php';

$PASS = 0; $FAIL = 0;
function ok($n) { global $PASS; $PASS++; echo "  ok    $n\n"; }
function bad($n, $why) { global $FAIL; $FAIL++; echo "  FAIL  $n\n        $why\n"; }
function check($n, $cond, $why = '') { $cond ? ok($n) : bad($n, $why === '' ? 'condition was false' : $why); }
function same($n, $want, $got) { $want === $got ? ok($n) : bad($n, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true)); }

$EXPECTED = array('hotel', 'clinic', 'ecommerce', 'real_estate');

echo "== template files ==\n";
$keys = industry_template_keys();
foreach ($EXPECTED as $k) check("$k.json exists", in_array($k, $keys, true), "not found in " . implode(',', $keys));
same('no unexpected extra verticals', array(), array_values(array_diff($keys, $EXPECTED)));

echo "\n== schema validation (all verticals) ==\n";
$templates = array();
foreach ($EXPECTED as $k) {
    $t = industry_template_load($k);
    if ($t === null) { bad("$k loads", 'industry_template_load returned null'); continue; }
    $templates[$k] = $t;
    $errors = industry_template_validate($t);
    check("$k passes the template schema", empty($errors), implode("\n        ", $errors));
}

echo "\n== a broken template is actually rejected ==\n";
if (isset($templates['hotel'])) {
    $mut = $templates['hotel'];
    $mut['ai_employee']['tone'] = 'Formal English';
    check('wrong tone is rejected', !empty(industry_template_validate($mut)), 'validator accepted a non-Egyptian tone');

    $mut = $templates['hotel'];
    $mut['ai_employee']['restrictions']['max_discount_percent'] = 10;
    check('a non-zero max discount is rejected', !empty(industry_template_validate($mut)), 'validator accepted max_discount_percent 10');

    $mut = $templates['hotel'];
    $mut['starter_faq'][0]['answer']['ar'] = 'الدخول ٢ الضهر';
    check('an unmarked starter answer is rejected', !empty(industry_template_validate($mut)), 'validator accepted an answer with no "عدّلني" marker');

    $mut = $templates['hotel'];
    $mut['pricing_config']['accommodation']['rooms'][0]['nightly'] = 0;
    check('a zero nightly price is rejected', !empty(industry_template_validate($mut)), 'validator accepted nightly = 0');

    $mut = $templates['hotel'];
    array_splice($mut['starter_faq'], 3);
    check('fewer than 6 starter questions is rejected', !empty(industry_template_validate($mut)), 'validator accepted 3 starter questions');
}

echo "\n== pricing: only what the calculator supports ==\n";
if (isset($templates['hotel'])) {
    same('hotel declares pricing supported', true, $templates['hotel']['pricing_supported']);
    $perrs = pricing_validate_config($templates['hotel']['pricing_config']);
    check('hotel skeleton passes pricing_validate_config()', empty($perrs), implode('; ', $perrs));

    // the skeleton must actually compute, not merely validate
    $r = pricing_calc_accommodation($templates['hotel']['pricing_config'], 'شاليه عائلي', 4, 4, 0, 0, 0);
    same('hotel 4-night package offer computes', 7000, $r['total']);
    $r = pricing_calc_day_use($templates['hotel']['pricing_config'], '', 3, 0, 0, 0);
    same('hotel day-use computes 3 x 500', 1500, $r['total']);
}
foreach (array('clinic', 'ecommerce', 'real_estate') as $k) {
    if (!isset($templates[$k])) continue;
    same("$k declares pricing NOT supported", false, $templates[$k]['pricing_supported']);
    same("$k carries no pricing_config", null, $templates[$k]['pricing_config']);
    check("$k explains where prices go instead", trim(industry_template_text($templates[$k]['pricing_note'], 'ar')) !== '', 'empty pricing_note.ar');
}

echo "\n== compiled persona ==\n";
if (isset($templates['hotel'])) {
    $c = industry_template_compile_prompt($templates['hotel'], array('business_name' => 'قرية بيوت', 'extra' => 'إحنا مقفولين يوم التلات.'));
    same('name falls back to the template suggestion', 'منى', $c['agent_name']);
    check('identity carries the business name', mb_strpos($c['instruction_to_ai'], 'قرية بيوت') !== false, $c['instruction_to_ai']);
    check('prompt states the zero discount cap', mb_strpos($c['sales_system_prompt'], 'أقصى خصم مسموح بيه: 0%') !== false, 'discount cap missing');
    foreach ($templates['hotel']['ai_employee']['restrictions']['rules'] as $r) {
        if (mb_strpos($c['sales_system_prompt'], $r['ar']) === false) { bad('every restriction reaches the prompt', 'missing rule ' . $r['key']); $r = null; break; }
    }
    if ($r !== null) ok('every restriction reaches the prompt');
    foreach ($templates['hotel']['qualification_questions'] as $q) {
        if (mb_strpos($c['sales_system_prompt'], $q['ar']) === false) { bad('every qualification question reaches the prompt', 'missing ' . $q['key']); $q = null; break; }
    }
    if ($q !== null) ok('every qualification question reaches the prompt');
    check("the owner's extra notes reach the prompt", mb_strpos($c['sales_system_prompt'], 'مقفولين يوم التلات') !== false, 'extra missing');

    $c2 = industry_template_compile_prompt($templates['hotel'], array('agent_name' => 'سارة', 'role' => 'مسؤولة حجز'));
    same('a typed name wins over the suggestion', 'سارة', $c2['agent_name']);
    check('a typed role wins over the suggestion', mb_strpos($c2['instruction_to_ai'], 'مسؤولة حجز') !== false, $c2['instruction_to_ai']);
    same('compiling twice with the same input is stable', $c['sales_system_prompt'],
        industry_template_compile_prompt($templates['hotel'], array('business_name' => 'قرية بيوت', 'extra' => 'إحنا مقفولين يوم التلات.'))['sales_system_prompt']);
}

echo "\n== starter FAQ idempotency (fake DB) ==\n";
/**
 * A minimal in-memory stand-in for the ai_faq table with the migration's unique index on
 * (user_id, source_key). Applying the plan through it is exactly what the controller does.
 */
final class FakeFaqTable
{
    public $rows = array();
    private $next = 1;
    public function existing($user_id)
    {
        $out = array();
        foreach ($this->rows as $r) {
            if ((int) $r['user_id'] === (int) $user_id && $r['source_key'] !== null) $out[$r['source_key']] = $r;
        }
        return $out;
    }
    public function apply($user_id, $plan)
    {
        $ins = 0; $upd = 0;
        foreach ($plan as $p) {
            if ($p['op'] === 'insert') {
                foreach ($this->rows as $r) {
                    if ((int) $r['user_id'] === (int) $user_id && $r['source_key'] === $p['source_key']) {
                        throw new RuntimeException('unique index violated: duplicate ' . $p['source_key'] . ' for user ' . $user_id);
                    }
                }
                $this->rows[$this->next] = array('id' => $this->next, 'user_id' => (int) $user_id,
                    'source_key' => $p['source_key'], 'question' => $p['question'], 'answer' => $p['answer'], 'status' => '1');
                $this->next++; $ins++;
            } else {
                $this->rows[$p['id']]['question'] = $p['question'];
                if ($p['answer'] !== null) $this->rows[$p['id']]['answer'] = $p['answer'];
                $upd++;
            }
        }
        return array($ins, $upd);
    }
    public function countFor($user_id) { $n = 0; foreach ($this->rows as $r) if ((int) $r['user_id'] === (int) $user_id) $n++; return $n; }
    public function find($user_id, $source_key)
    {
        foreach ($this->rows as $r) if ((int) $r['user_id'] === (int) $user_id && $r['source_key'] === $source_key) return $r;
        return null;
    }
}

if (isset($templates['hotel'])) {
    $tpl = $templates['hotel'];
    $db = new FakeFaqTable();
    // a FAQ the owner wrote by hand: source_key NULL, must survive every apply untouched
    $db->rows[900] = array('id' => 900, 'user_id' => 11, 'source_key' => null, 'question' => 'بتقبلوا حيوانات أليفة؟', 'answer' => 'أيوه، بشرط.', 'status' => '1');

    list($i1, $u1) = $db->apply(11, industry_template_faq_plan($tpl, $db->existing(11)));
    same('first apply inserts every starter question', count($tpl['starter_faq']), $i1);
    same('first apply updates nothing', 0, $u1);

    try {
        list($i2, $u2) = $db->apply(11, industry_template_faq_plan($tpl, $db->existing(11)));
        same('second apply inserts nothing', 0, $i2);
        same('second apply updates in place', count($tpl['starter_faq']), $u2);
    } catch (RuntimeException $e) { bad('second apply does not violate the unique index', $e->getMessage()); }

    same('row count stays starters + 1 hand-written', count($tpl['starter_faq']) + 1, $db->countFor(11));

    // owner edits an answer, then the step is re-run with the field left blank
    $sk = industry_template_faq_source_key('hotel', $tpl['starter_faq'][0]['key']);
    $row = $db->find(11, $sk);
    $db->rows[$row['id']]['answer'] = 'الدخول ٣ العصر والخروج ١١ الصبح.';
    $db->apply(11, industry_template_faq_plan($tpl, $db->existing(11)));
    same("a blank field does not wipe the owner's answer", 'الدخول ٣ العصر والخروج ١١ الصبح.', $db->find(11, $sk)['answer']);

    // ... and a typed answer does replace it
    $db->apply(11, industry_template_faq_plan($tpl, $db->existing(11), array($tpl['starter_faq'][0]['key'] => 'الدخول ٢ الضهر.')));
    same('a typed answer replaces the stored one', 'الدخول ٢ الضهر.', $db->find(11, $sk)['answer']);

    same('the hand-written FAQ is never touched', 'أيوه، بشرط.', $db->rows[900]['answer']);

    // a second tenant applying the same template must not collide with the first
    $db->apply(12, industry_template_faq_plan($tpl, $db->existing(12)));
    same('tenant B gets its own copy of the starters', count($tpl['starter_faq']), $db->countFor(12));
    same('tenant A is unchanged by tenant B', count($tpl['starter_faq']) + 1, $db->countFor(11));

    // switching vertical adds the new template's rows without removing the old ones
    if (isset($templates['clinic'])) {
        $db->apply(11, industry_template_faq_plan($templates['clinic'], $db->existing(11)));
        same('switching vertical adds, never replaces', count($tpl['starter_faq']) + count($templates['clinic']['starter_faq']) + 1, $db->countFor(11));
    }
}

echo "\n== source keys are namespaced per vertical ==\n";
$all = array();
foreach ($templates as $k => $t) {
    foreach ($t['starter_faq'] as $f) $all[] = industry_template_faq_source_key($k, $f['key']);
}
same('no two verticals share a FAQ source key', count($all), count(array_unique($all)));
check('source keys fit ai_faq.source_key VARCHAR(64)', max(array_map('strlen', $all)) <= 64, 'longest is ' . max(array_map('strlen', $all)));

echo "\n== unknown keys ==\n";
same('an unknown vertical loads as null', null, industry_template_load('barbershop'));
same('a path-traversal key loads as null', null, industry_template_load('../../config/database'));

echo "\n== step 5 follows the chosen pages ==\n";
// the wizard's target keys vs pricing's scopes: 'wa' is WhatsApp Cloud, 'openwa' the personal number
same('fb page maps to fb', array('fb', '12'), industry_template_pricing_scope('fb:12'));
same("wizard 'wa' is WhatsApp Cloud", array('wa_cloud', '5'), industry_template_pricing_scope('wa:5'));
same("wizard 'openwa' is the personal number", array('openwa', '5'), industry_template_pricing_scope('openwa:5'));
check('cloud #5 and personal #5 never share a scope', industry_template_pricing_scope('wa:5') !== industry_template_pricing_scope('openwa:5'));
same('telegram maps to tg', array('tg', '7'), industry_template_pricing_scope('tg:7'));
same('web keeps its widget key', array('web', 'wk_ab12'), industry_template_pricing_scope('web:wk_ab12'));
foreach (array('', 'fb', 'fb:', 'fb:abc', 'ig:12', 'wa_cloud:5', 'x:1', 'web:' . str_repeat('a', 65)) as $bad)
    same("bad key '" . substr($bad, 0, 12) . "' maps to nothing (never the default list)", null, industry_template_pricing_scope($bad));
foreach (array('fb:12', 'wa:5', 'openwa:5', 'tg:7', 'web:wk_ab12') as $k) {
    $sc = industry_template_pricing_scope($k);
    same("$k is a scope pricing itself accepts", $sc, pricing_normalize_scope($sc));
}

$sc = array('fb:1' => array('fb', '1'), 'fb:2' => array('fb', '2'), 'wa:3' => array('wa_cloud', '3'));
$pl = industry_template_pricing_plan($sc, array('fb:2' => true), false, 1);
same('a page without a list gets one', array('fb:1', 'wa:3'), array_keys($pl['write']));
same('a page that has a list is kept, not overwritten', array('fb:2'), $pl['kept']);
same('no tick never writes the default list', false, $pl['default']);
$pl = industry_template_pricing_plan($sc, array(), false, 0);
same('even with zero lists, no tick = per page', false, $pl['default']);
same('even with zero lists, no tick writes every page', 3, count($pl['write']));
$pl = industry_template_pricing_plan($sc, array(), true, 0);
same('tick + zero lists = the default list', true, $pl['default']);
same('tick + zero lists writes no page list', array(), $pl['write']);
$pl = industry_template_pricing_plan($sc, array(), true, 1);
check('tick with an existing list is refused', $pl['error'] !== null && !$pl['default'] && empty($pl['write']), json_encode($pl));

same('nothing typed = no catalog (skip)', null, industry_template_catalog_config(array(array('name' => ' ', 'price' => ''), array())));
$cc = industry_template_catalog_config(array(
    array('name' => 'كشف', 'price' => '300', 'unit' => 'session'),
    array('name' => '', 'price' => ''),
    array('name' => 'متابعة', 'price' => '150.5', 'unit' => 'item'),
), 'جنيه');
same('blank rows are dropped', 2, count($cc['catalog']['items']));
same('whole prices are numbers', 300, $cc['catalog']['items'][0]['price']);
same('decimal prices stay exact', 150.5, $cc['catalog']['items'][1]['price']);
same('the unit is kept', 'session', $cc['catalog']['items'][0]['unit']);
check("'item' unit is the default and not stored", !isset($cc['catalog']['items'][1]['unit']));
same('currency word kept', 'جنيه', $cc['currency']);
$perrs = pricing_validate_config($cc);
check('a typed catalog passes the REAL pricing_validate_config()', empty($perrs), implode('; ', $perrs));
check('a price with no name is rejected by the real validator',
    !empty(pricing_validate_config(industry_template_catalog_config(array(array('name' => '', 'price' => '100'))))));
check('a zero price is rejected by the real validator',
    !empty(pricing_validate_config(industry_template_catalog_config(array(array('name' => 'x', 'price' => '0'))))));
check('a word as price is rejected by the real validator',
    !empty(pricing_validate_config(industry_template_catalog_config(array(array('name' => 'x', 'price' => 'كتير'))))));
check('an unknown unit is rejected by the real validator',
    !empty(pricing_validate_config(industry_template_catalog_config(array(array('name' => 'x', 'price' => '5', 'unit' => 'bogus'))))));
check('a name with no price is rejected by the real validator',
    !empty(pricing_validate_config(industry_template_catalog_config(array(array('name' => 'x', 'price' => ''))))));
same('duplicate names are rejected by the real validator', true,
    count(pricing_validate_config(industry_template_catalog_config(array(array('name' => 'x', 'price' => '5'), array('name' => 'X', 'price' => '6'))))) > 0);

// the controller writes the default list in exactly one place, behind the plan's 'default'
$src = file_get_contents(APPPATH . 'controllers/Onboarding.php');
preg_match('/function save_pricing\(\)(.*?)\n    }\n/s', $src, $m);
$body = $m[1] ?? '';
same('save_pricing writes the default list in exactly one place', 1, preg_match_all('/pricing_save_config\([^;]*,\s*null\)/', $body));
check('...and only when the plan said so', (bool) preg_match('/if \(\$plan\[\'default\'\]\) \{\s*list\(\$ok, \$errs\) = pricing_save_config\([^;]*null\)/', $body));
check('save_pricing validates with the real validator before writing', strpos($body, 'pricing_validate_config($cfg)') !== false
    && strpos($body, 'pricing_validate_config($cfg)') < strpos($body, 'pricing_save_config('));

echo "\n== deploy boundary: data never lives under application/config ==\n";
// On production application/config is a Docker volume that shadows the code tree, so any
// file shipped there is invisible to the running app (this is exactly how the Setup Guide
// shipped with zero business types). Templates must live somewhere the image actually sees.
check('template dir is not under application/config', strpos(industry_template_dir(), '/config/') === false, industry_template_dir());
$root = dirname(__DIR__, 2);
$stock = array('autoload.php','config.php','constants.php','database.php','doctypes.php','foreign_chars.php','frontend_config.php','grocery_crud.php','hooks.php','index.html','method_check.php','migration.php','mimes.php','my_config.php','package_config.php','profiler.php','pusher.php','recommendation_config.php','routes.php','smileys.php','tiktok.php','user_agents.php');
$extra = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/application/config', FilesystemIterator::SKIP_DOTS)) as $f) {
    $rel = substr($f->getPathname(), strlen($root . '/application/config/'));
    if (!in_array($rel, $stock, true) && !preg_match('/\.bak/', $rel)) $extra[] = $rel;
}
check('no new files under application/config (they would never reach production)', empty($extra), implode(', ', $extra));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 && $PASS > 0 ? 0 : 1);
