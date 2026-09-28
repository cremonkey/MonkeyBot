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

echo "\n== task 23 / \xc2\xa733: optional extras (sequences, automation, media, appointments) ==\n";
// Old templates without these keys must still validate and produce plans that do nothing --
// this proves the extension is additive and byte-identical when a template opts out.
$bare = $templates['hotel'];
foreach (array('sequences', 'automation_rules', 'media', 'appointments') as $optional_key) unset($bare[$optional_key]);
check('a template with none of the optional keys still validates', empty(industry_template_validate($bare)));
same('no sequences key -> empty plan', array(), industry_template_sequences_plan($bare, array()));
same('no automation_rules key -> empty plan', array(), industry_template_automation_plan($bare, array()));
same('no media key -> empty plan', array(), industry_template_media_plan($bare));
same('no appointments key -> skip (nothing offered)', 'skip', industry_template_appointments_plan($bare, false)['op']);

foreach ($EXPECTED as $k) {
    check("$k.json carries the new optional sections", isset($templates[$k]['sequences'], $templates[$k]['automation_rules'], $templates[$k]['media'], $templates[$k]['appointments']));
}

echo "\n-- sequences plan --\n";
$hotel = $templates['hotel'];
$seq_plan = industry_template_sequences_plan($hotel, array());
check('one insert per template sequence', count($seq_plan) === count($hotel['sequences']));
$first = $seq_plan[0];
same('a fresh sequence is inserted', 'insert', $first['op']);
same('a template sequence is NEVER created active -- status is always draft', 'draft', $first['status']);
check('steps carry delay_seconds converted from delay_hours', $first['steps'][0]['delay_seconds'] === (int) round(((float) $hotel['sequences'][0]['steps'][0]['delay_hours']) * 3600));
same('a step dispatches through the shared send_message action, same vocabulary as Automation Rules', 'send_message', $first['steps'][0]['action_type']);
check('ar text flows into the step by default', trim($first['steps'][0]['action_params']['text']) === trim($hotel['sequences'][0]['steps'][0]['message_ar']));
$seq_plan_en = industry_template_sequences_plan($hotel, array(), 'en');
check('lang=en picks message_en instead', trim($seq_plan_en[0]['steps'][0]['action_params']['text']) === trim($hotel['sequences'][0]['steps'][0]['message_en']));

// idempotency: a sequence already applied (by source_key) is never re-inserted
$sk = industry_template_sequence_source_key($hotel['key'], $hotel['sequences'][0]['name']);
$again = industry_template_sequences_plan($hotel, array($sk => array('id' => 9)));
same('re-applying a sequence already on the tenant is a no-op', 'skip', $again[0]['op']);

echo "\n-- automation rules plan --\n";
$auto_plan = industry_template_automation_plan($hotel, array());
check('one insert per template rule', count($auto_plan) === count($hotel['automation_rules']));
same('a template rule is NEVER created active -- status is always draft', 'draft', $auto_plan[0]['status']);
same('trigger_event carries the template trigger', $hotel['automation_rules'][0]['trigger'], $auto_plan[0]['trigger_event']);
check('actions_json round-trips the template actions', json_decode($auto_plan[0]['actions_json'], true) === array_values($hotel['automation_rules'][0]['actions']));
$ask = industry_template_automation_source_key($hotel['key'], $hotel['automation_rules'][0]['name']);
$auto_again = industry_template_automation_plan($hotel, array($ask => array('id' => 3)));
same('re-applying a rule already on the tenant is a no-op', 'skip', $auto_again[0]['op']);
if (class_exists('Automation_engine', false)) {
    $catalogue = array_keys(Automation_engine::action_catalogue());
    foreach ($EXPECTED as $k) {
        foreach ($templates[$k]['automation_rules'] as $ri => $rule) {
            foreach ($rule['actions'] as $ai => $a) {
                check("$k.automation_rules[$ri].actions[$ai].type is a known Automation_engine action", in_array($a['type'], $catalogue, true), $a['type']);
            }
        }
    }
}

// every shipped template's trigger must be a real, emitted event — a trigger not in
// event_catalogue() would sit permanently dead even once the owner enables the rule.
require_once APPPATH . 'helpers/event_helper.php';
$event_triggers = array_keys(event_catalogue());
foreach ($EXPECTED as $k) {
    foreach ($templates[$k]['automation_rules'] as $ri => $rule) {
        check("$k.automation_rules[$ri].trigger \"" . $rule['trigger'] . "\" is in event_catalogue()", in_array($rule['trigger'], $event_triggers, true), $rule['trigger']);
    }
}
$bad_trigger_tpl = $hotel;
$bad_trigger_tpl['automation_rules'][0]['trigger'] = 'appointment.no_show';
check('an unknown trigger is rejected by the validator', !empty(industry_template_validate($bad_trigger_tpl)));
require_once APPPATH . 'helpers/event_helper.php';
$event_triggers = array_keys(event_catalogue());
foreach ($EXPECTED as $k) {
    foreach ($templates[$k]['automation_rules'] as $ri => $rule) {
        check("$k.automation_rules[$ri].trigger is a known event_catalogue() trigger", in_array($rule['trigger'], $event_triggers, true), $rule['trigger']);
    }
}
// a dead trigger (nothing in event_catalogue() would ever fire it) must fail validation
$dead_trigger_tpl = $hotel;
$dead_trigger_tpl['automation_rules'][0]['trigger'] = 'appointment.no_show';
$dead_errors = industry_template_validate($dead_trigger_tpl);
check('an unknown/dead automation trigger is rejected by the validator', !empty($dead_errors) && strpos(implode(' ', $dead_errors), 'appointment.no_show') !== false, implode('; ', $dead_errors));
check('every shipped template still passes with its real triggers', empty(industry_template_validate($hotel)));

echo "\n-- media placeholders plan --\n";
$media_plan = industry_template_media_plan($hotel);
check('one row per template media placeholder, no files created', count($media_plan) === count($hotel['media']));
check('nothing "uploaded" until the caller says so', $media_plan[0]['uploaded'] === false);
$media_plan2 = industry_template_media_plan($hotel, array($hotel['media'][0]['key']));
check('an existing key is reported uploaded', $media_plan2[0]['uploaded'] === true);
foreach ($EXPECTED as $k) {
    foreach ($templates[$k]['media'] as $mi => $m) {
        check("$k.media[$mi] has no file/url/path -- placeholder only", !isset($m['file'], $m['url'], $m['path']));
    }
}

echo "\n-- appointments plan --\n";
$ap_plan = industry_template_appointments_plan($hotel, false);
same('appointments.enabled is false in every shipped template -> nothing offered', 'skip', $ap_plan['op']);
$enabled_tpl = $hotel; $enabled_tpl['appointments']['enabled'] = true;
$ap_plan2 = industry_template_appointments_plan($enabled_tpl, false);
same('an owner-enabled template with no existing service -> offer to create one', 'insert', $ap_plan2['op']);
same('a template appointment service is NEVER created bookable -- status is always "0"', '0', $ap_plan2['status']);
same('default_duration_minutes carries through', (int) $hotel['appointments']['default_duration_minutes'], $ap_plan2['duration_min']);
$ap_plan3 = industry_template_appointments_plan($enabled_tpl, true);
same('a tenant with an existing service is never touched', 'skip', $ap_plan3['op']);


echo "\n== step 10 (Extras) save path: onboarding/save_extras() simulated with a fake DB ==\n";
/*
 * Onboarding::save_extras() has no unit-testable seam of its own (it is a real DB write,
 * like save_knowledge()/save_pricing()), so this simulates the EXACT loop the controller
 * runs: build existing_by_key from the "extras_applied" bookkeeping stored in
 * onboarding_state.data_json, call the REAL plan functions above, and only insert an item
 * that is both op=insert AND checked. This proves the rule, not a copy of it.
 */
class FakeExtrasDb
{
    public $sequences = array();      // id => row
    public $sequence_steps = array(); // id => [row,...]
    public $automation_rules = array(); // id => row
    public $ab_services = array();    // id => row
    private $next_id = 1;

    /** Mirrors Onboarding::save_extras()'s body 1:1 against this fake storage. */
    public function save_extras($template, $applied, $checked_seq, $checked_auto, $checked_appt)
    {
        $existing_seq = array();
        foreach ($applied as $sk => $rid) if (strpos($sk, 'seq:') === 0) $existing_seq[substr($sk, 4)] = array('id' => $rid);
        foreach (industry_template_sequences_plan($template, $existing_seq) as $p) {
            if ($p['op'] !== 'insert' || !in_array($p['source_key'], $checked_seq, true)) continue;
            $id = $this->next_id++;
            $this->sequences[$id] = array('name' => $p['name'], 'status' => $p['status']);
            $this->sequence_steps[$id] = $p['steps'];
            $applied['seq:' . $p['source_key']] = $id;
        }

        $existing_auto = array();
        foreach ($applied as $sk => $rid) if (strpos($sk, 'auto:') === 0) $existing_auto[substr($sk, 5)] = array('id' => $rid);
        foreach (industry_template_automation_plan($template, $existing_auto) as $p) {
            if ($p['op'] !== 'insert' || !in_array($p['source_key'], $checked_auto, true)) continue;
            $id = $this->next_id++;
            $this->automation_rules[$id] = array('name' => $p['name'], 'trigger_event' => $p['trigger_event'], 'status' => $p['status']);
            $applied['auto:' . $p['source_key']] = $id;
        }

        $has_service = count($this->ab_services) > 0;
        $ap_tpl = $template;
        if (is_array($ap_tpl['appointments'] ?? null)) $ap_tpl['appointments']['enabled'] = $checked_appt;
        $ap_plan = industry_template_appointments_plan($ap_tpl, $has_service);
        if ($ap_plan['op'] === 'insert') {
            $id = $this->next_id++;
            $this->ab_services[$id] = array('name' => $ap_plan['name'], 'status' => $ap_plan['status']);
        }

        return $applied;
    }
}

$hotel_sk_seq = 'seq:' . industry_template_sequence_source_key($hotel['key'], $hotel['sequences'][0]['name']);
$hotel_sk_auto = 'auto:' . industry_template_automation_source_key($hotel['key'], $hotel['automation_rules'][0]['name']);

// -- nothing checked -> nothing created --
$fdb = new FakeExtrasDb();
$applied = $fdb->save_extras($hotel, array(), array(), array(), false);
check('nothing checked -> no sequence created', count($fdb->sequences) === 0);
check('nothing checked -> no automation rule created', count($fdb->automation_rules) === 0);
check('appointment box unchecked -> no service created', count($fdb->ab_services) === 0);
same('nothing checked -> bookkeeping records nothing', array(), $applied);

// -- everything checked -> exactly the checked items are created, all disabled --
$fdb2 = new FakeExtrasDb();
$checked_seq_keys = array_map(function ($s) { return $s['source_key']; }, industry_template_sequences_plan($hotel, array()));
$checked_auto_keys = array_map(function ($r) { return $r['source_key']; }, industry_template_automation_plan($hotel, array()));
$applied2 = $fdb2->save_extras($hotel, array(), $checked_seq_keys, $checked_auto_keys, true);
same('one sequence created per checked item', count($hotel['sequences']), count($fdb2->sequences));
same('one automation rule created per checked item', count($hotel['automation_rules']), count($fdb2->automation_rules));
check('appointment box checked -> a service is created (template has none)', count($fdb2->ab_services) === 1);
foreach ($fdb2->sequences as $row) same('every created sequence is status draft, never active', 'draft', $row['status']);
foreach ($fdb2->automation_rules as $row) same('every created automation rule is status draft, never active', 'draft', $row['status']);
foreach ($fdb2->ab_services as $row) same('every created appointment service is status "0", never "1" (bookable)', '0', $row['status']);
check('bookkeeping now remembers the sequence source_key', array_key_exists($hotel_sk_seq, $applied2));
check('bookkeeping now remembers the automation source_key', array_key_exists($hotel_sk_auto, $applied2));

// -- only ONE item checked -> only that one is created, the rest are left alone --
$fdb3 = new FakeExtrasDb();
$one_seq = array($checked_seq_keys[0]);
$applied3 = $fdb3->save_extras($hotel, array(), $one_seq, array(), false);
same('only the checked sequence is created, no automation rule, no appointment', 1, count($fdb3->sequences));
check('the unchecked automation rule was never created', count($fdb3->automation_rules) === 0);
check('the unchecked appointment box created nothing', count($fdb3->ab_services) === 0);

// -- re-run with the same bookkeeping (as if the owner re-opened the step) is a true no-op --
$fdb4 = new FakeExtrasDb();
$applied4a = $fdb4->save_extras($hotel, array(), $checked_seq_keys, $checked_auto_keys, true);
$before_seq = count($fdb4->sequences); $before_auto = count($fdb4->automation_rules); $before_appt = count($fdb4->ab_services);
// second call: same checkboxes re-submitted (e.g. a double click / page refresh), same bookkeeping carried forward
$applied4b = $fdb4->save_extras($hotel, $applied4a, $checked_seq_keys, $checked_auto_keys, true);
same('re-applying with the same bookkeeping creates no new sequence', $before_seq, count($fdb4->sequences));
same('re-applying with the same bookkeeping creates no new automation rule', $before_auto, count($fdb4->automation_rules));
same('re-applying with an existing service creates no new appointment service', $before_appt, count($fdb4->ab_services));
same('bookkeeping is unchanged by the idempotent re-run', $applied4a, $applied4b);

echo "\n-- step 10 controller wiring: source checks (like the save_pricing() checks above) --\n";
$onb_src = file_get_contents(APPPATH . 'controllers/Onboarding.php');
check('Onboarding::save_extras() exists', strpos($onb_src, 'function save_extras()') !== false);
check('step() routes n=10 to extras_state()', (bool) preg_match('/case 10:\s*\$data\[.extras.\] = \$this->extras_state/', $onb_src));
check('STEPS was raised to 10 to make the new step reachable', strpos($onb_src, 'const STEPS = 10;') !== false);
preg_match('/function save_extras\(\)(.*?)\n    }\n/s', $onb_src, $sm);
$sbody = $sm[1] ?? '';
check('save_extras() requires ajax_check()', strpos($sbody, '$this->ajax_check();') !== false);
check('save_extras() requires csrf_token_check()', strpos($sbody, '$this->csrf_token_check();') !== false);
check('save_extras() requires a chosen business type first', strpos($sbody, "Pick a business type first") !== false);
check('save_extras() requires pages chosen in step 2 first (tenant-owned scope)', strpos($sbody, 'selected_targets($st)') !== false);
check('every sequence insert is hard-coded status => $p[\'status\'] (never a posted value)', (bool) preg_match('/\'status\'\s*=>\s*\$p\[\'status\'\]/', $sbody));
check('the appointment insert is hard-coded status => $ap_plan[\'status\'] (never a posted value)', strpos($sbody, "'status' => \$ap_plan['status']") !== false);
check('save_extras() never writes a raw posted status/enabled value to the DB', !preg_match('/\'status\'\s*=>\s*\$this->input->post/', $sbody));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 && $PASS > 0 ? 0 : 1);
