<?php
/**
 * Prompt-to-Flow unit tests (brief §24). No CodeIgniter, no database, no network — the AI
 * call is a fake callable injected into Flow_prompt_generator's constructor, exactly like
 * tests/automation/run.php substitutes a FakeGateway into Automation_engine.
 *
 *   php tests/prompt_to_flow/run.php
 *   docker exec -u www-data <app> php /var/www/html/tests/prompt_to_flow/run.php
 *
 * Covers: Flow_prompt_validator::validate() accepting well-formed v1 flows (including a
 * hotel-booking flow shaped like the master brief's own §24 example) and rejecting every
 * documented failure mode; Flow_prompt_generator::build_draft()'s retry-once-then-fail
 * behaviour on bad AI output, and its two safety fallbacks (CRM-deal -> label substitution,
 * unowned httpApi -> price placeholder substitution) — never silently, always reported.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
define('APPPATH', dirname(__DIR__) . '/../application/');
date_default_timezone_set('UTC');
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

require __DIR__ . '/../../application/libraries/Flow_prompt_validator.php';
require __DIR__ . '/../../application/libraries/Flow_prompt_generator.php';

// ---------------------------------------------------------------- tiny harness

$PASS = 0; $FAIL = 0;
function ok($name) { global $PASS; $PASS++; echo "PASS  $name\n"; }
function bad($name, $why) { global $FAIL; $FAIL++; echo "FAIL  $name  -> $why\n"; }
function is_same($name, $expected, $actual) {
    if ($expected === $actual) ok($name);
    else bad($name, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function is_true($name, $v, $ctx = '') { if ($v === true) ok($name); else bad($name, 'not true ' . $ctx); }
function is_false($name, $v, $ctx = '') { if ($v === false) ok($name); else bad($name, 'not false ' . $ctx); }
function contains($name, $haystack, $needle) {
    if (strpos((string) $haystack, $needle) !== false) ok($name);
    else bad($name, "\"$haystack\" does not contain \"$needle\"");
}

function ai_response($text) { return json_encode(array('choices' => array(array('text' => $text)))); }

// ---------------------------------------------------------------- fixtures

/** Minimal but complete: reference -> text (terminal). */
function fixture_minimal()
{
    return array('nodes' => array(
        '1' => array('id' => 1, 'data' => array(
            'xitFbpostbackId' => 'REF1', 'title' => 'Greeting', 'postbackId' => 'REFP1',
            'triggerKeyword' => 'hello', 'triggerMatchingType' => 'contains',
        ), 'inputs' => array('referenceInput' => array('connections' => array())),
           'outputs' => array('referenceOutput' => array('connections' => array(array('node' => '2', 'input' => 'textInput')))),
           'pos_x' => 0, 'pos_y' => 0),
        '2' => array('id' => 2, 'data' => array(
            'textMessage' => 'Hi there!', 'uniqueId' => 'U1', 'delayReplyFor' => 0, 'IsTypingOnDisplayChecked' => false,
        ), 'inputs' => array('textInput' => array('connections' => array(array('node' => '1', 'input' => 'referenceOutput')))),
           'outputs' => array('textOutput' => array('connections' => array())),
           'pos_x' => 100, 'pos_y' => 0),
    ));
}

/**
 * The master brief's own §24 example: hotel booking. Ask arrival date / departure date /
 * guest count via userInputFlow, then a price step (variant selects httpApi or the
 * documented text fallback), then a confirmation quick reply, then a label-as-lead-save on
 * a postback node.
 */
function fixture_hotel($price_variant)
{
    if ($price_variant === 'httpApi') {
        $price_node = array('id' => 6, 'data' => array('httpApiId' => 42),
            'inputs' => array('httpApiInput' => array('connections' => array(array('node' => '5', 'input' => 'userInputFlowSingleOutput')))),
            'outputs' => array('httpApiOutput' => array('connections' => array(array('node' => '7', 'input' => 'textInput')))),
            'pos_x' => 500, 'pos_y' => 0);
    } else {
        $price_node = array('id' => 6, 'data' => array(
            'textMessage' => Flow_prompt_generator::PRICE_PLACEHOLDER, 'uniqueId' => 'PRICEU',
            'delayReplyFor' => 0, 'IsTypingOnDisplayChecked' => false,
        ), 'inputs' => array('textInput' => array('connections' => array(array('node' => '5', 'input' => 'userInputFlowSingleOutput')))),
           'outputs' => array('textOutput' => array('connections' => array(array('node' => '7', 'input' => 'textInput')))),
           'pos_x' => 500, 'pos_y' => 0);
    }

    return array('nodes' => array(
        '1' => array('id' => 1, 'data' => array(
            'xitFbpostbackId' => 'HOTELREF', 'title' => 'Hotel booking', 'postbackId' => 'HOTELP',
            'triggerKeyword' => 'hotel,booking', 'triggerMatchingType' => 'contains', 'labelIds' => array(),
        ), 'inputs' => array('referenceInput' => array('connections' => array())),
           'outputs' => array('referenceOutput' => array('connections' => array(array('node' => '2', 'input' => 'userInputFlowInput')))),
           'pos_x' => 0, 'pos_y' => 0),

        '2' => array('id' => 2, 'data' => array('userInputFlowIdValue' => 'new'),
            'inputs' => array('userInputFlowInput' => array('connections' => array(array('node' => '1', 'input' => 'referenceOutput')))),
            'outputs' => array('userInputFlowOutput' => array('connections' => array(array('node' => '3', 'input' => 'userInputFlowSingleInput')))),
            'pos_x' => 100, 'pos_y' => 0),

        '3' => array('id' => 3, 'data' => array(
            'questionType' => 'date', 'question' => 'What is your arrival date?', 'replyType' => 'text', 'customField' => 10, 'uniqueId' => 'Q1',
        ), 'inputs' => array('userInputFlowSingleInput' => array('connections' => array(array('node' => '2', 'input' => 'userInputFlowOutput')))),
           'outputs' => array('userInputFlowSingleOutput' => array('connections' => array(array('node' => '4', 'input' => 'userInputFlowSingleInput')))),
           'pos_x' => 200, 'pos_y' => 0),

        '4' => array('id' => 4, 'data' => array(
            'questionType' => 'date', 'question' => 'What is your departure date?', 'replyType' => 'text', 'customField' => 11, 'uniqueId' => 'Q2',
        ), 'inputs' => array('userInputFlowSingleInput' => array('connections' => array(array('node' => '3', 'input' => 'userInputFlowSingleOutput')))),
           'outputs' => array('userInputFlowSingleOutput' => array('connections' => array(array('node' => '5', 'input' => 'userInputFlowSingleInput')))),
           'pos_x' => 300, 'pos_y' => 0),

        '5' => array('id' => 5, 'data' => array(
            'questionType' => 'number', 'question' => 'How many guests?', 'replyType' => 'text', 'customField' => 12, 'uniqueId' => 'Q3', 'newPostbackId' => 'EXIT1',
        ), 'inputs' => array('userInputFlowSingleInput' => array('connections' => array(array('node' => '4', 'input' => 'userInputFlowSingleOutput')))),
           'outputs' => array('userInputFlowSingleOutput' => array('connections' => array(array('node' => '6', 'input' => $price_variant === 'httpApi' ? 'httpApiInput' : 'textInput')))),
           'pos_x' => 400, 'pos_y' => 0),

        '6' => $price_node,

        '7' => array('id' => 7, 'data' => array(
            'textMessage' => 'Shall I confirm this booking?', 'uniqueId' => 'U7', 'delayReplyFor' => 0, 'IsTypingOnDisplayChecked' => false,
        ), 'inputs' => array('textInput' => array('connections' => array(array('node' => '6', 'input' => $price_variant === 'httpApi' ? 'httpApiOutput' : 'textOutput')))),
           'outputs' => array('textOutputQuickreply' => array('connections' => array(array('node' => '8', 'input' => 'quickReplyInput')))),
           'pos_x' => 600, 'pos_y' => 0),

        '8' => array('id' => 8, 'data' => array(
            'postbackId' => '', 'buttonText' => 'Yes, confirm', 'replyType' => 'new_post_back', 'newPostbackId' => 'CONFIRMLINK1',
        ), 'inputs' => array('quickReplyInput' => array('connections' => array(array('node' => '7', 'input' => 'textOutputQuickreply')))),
           'outputs' => array('quickReplyOutput' => array('connections' => array(array('node' => '9', 'input' => 'newPostbackInput')))),
           'pos_x' => 700, 'pos_y' => 0),

        '9' => array('id' => 9, 'data' => array(
            'title' => 'Booking confirmed', 'postbackId' => 'CONFIRMPOSTBACK1', 'labelIds' => array('Lead', 'Hotel booking'),
        ), 'inputs' => array('newPostbackInput' => array('connections' => array(array('node' => '8', 'input' => 'quickReplyOutput')))),
           'outputs' => array('newPostbackOutput' => array('connections' => array())),
           'pos_x' => 800, 'pos_y' => 0),
    ));
}

/** A condition-branch fixture: lead_score >= 50 (AND, one rule). */
function fixture_with_condition()
{
    $f = fixture_minimal();
    $f['nodes']['1']['outputs']['referenceOutput']['connections'] = array(array('node' => '3', 'input' => 'conditionInput'));
    $f['nodes']['3'] = array('id' => 3, 'data' => array(
        'all_match' => true, 'any_match' => false,
        'custom_field_variable' => array('lead_score'),
        'custom_field_operator' => array('greater_than_or_equal'),
        'custom_field_variable_value' => array('50'),
    ), 'inputs' => array('conditionInput' => array('connections' => array(array('node' => '1', 'input' => 'referenceOutput')))),
       'outputs' => array(
            'conditionOutputTrue' => array('connections' => array(array('node' => '2', 'input' => 'textInput'))),
            'conditionOutputFalse' => array('connections' => array()),
       ), 'pos_x' => 200, 'pos_y' => 0);
    $f['nodes']['2']['inputs']['textInput']['connections'] = array(array('node' => '3', 'input' => 'conditionOutputTrue'));
    return $f;
}

// ---------------------------------------------------------------- validator: accept

foreach (array('minimal', 'condition') as $name) {
    $f = $name === 'minimal' ? fixture_minimal() : fixture_with_condition();
    list($okv, $err) = Flow_prompt_validator::validate($f);
    is_true("validator accepts well-formed fixture: $name", $okv, $err);
}
foreach (array('httpApi', 'text_fallback') as $variant) {
    $f = fixture_hotel($variant === 'httpApi' ? 'httpApi' : 'text');
    $allowed = $variant === 'httpApi' ? array(42) : array();
    list($okv, $err) = Flow_prompt_validator::validate($f, $allowed);
    is_true("validator accepts hotel-booking fixture ($variant)", $okv, $err);
}

// ---------------------------------------------------------------- validator: reject

$f = fixture_minimal();
$f['nodes']['3'] = array('id' => 3, 'data' => array('title' => 'x'),
    'inputs' => array('mysteryInput' => array('connections' => array())), 'outputs' => array(), 'pos_x' => 0, 'pos_y' => 0);
list($okv, $err) = Flow_prompt_validator::validate($f);
is_false('validator rejects an unknown node type', $okv);
contains('  ...with a useful message', $err, 'unsupported input port');

$f = fixture_minimal();
$f['nodes']['3'] = array('id' => 3, 'data' => array('carouselItemTitle' => 'x'),
    'inputs' => array('carouselInput' => array('connections' => array())), 'outputs' => array('carouselOutput' => array('connections' => array())),
    'pos_x' => 0, 'pos_y' => 0);
list($okv, $err) = Flow_prompt_validator::validate($f);
is_false('validator rejects an out-of-scope v2+ node type (carousel)', $okv);
contains('  ...naming the unsupported port', $err, 'carouselInput');

$f = fixture_minimal();
$f['nodes']['1']['outputs']['referenceOutput']['connections'] = array(array('node' => '99', 'input' => 'textInput'));
list($okv, $err) = Flow_prompt_validator::validate($f);
is_false('validator rejects a dangling connection reference', $okv);
contains('  ...naming the missing node', $err, '"99"');

$f = fixture_minimal();
$f['nodes']['2']['data']['uniqueId'] = 'REF1'; // collides with node 1's xitFbpostbackId
list($okv, $err) = Flow_prompt_validator::validate($f);
is_false('validator rejects a duplicate unique-id field', $okv);
contains('  ...naming the duplicated value', $err, 'REF1');

$f = fixture_with_condition();
$f['nodes']['3']['data']['custom_field_operator'] = array(); // arrays no longer parallel/non-empty
list($okv, $err) = Flow_prompt_validator::validate($f);
is_false('validator rejects a malformed (non-parallel/empty) condition array', $okv);

$f = fixture_with_condition();
$f['nodes']['3']['data']['custom_field_operator'] = array('==');
list($okv, $err) = Flow_prompt_validator::validate($f);
is_false('validator rejects an unknown condition operator', $okv);

// ---------------------------------------------------------------- generator: retry then structured failure

$calls = 0;
$gen = new Flow_prompt_generator(array('ai_caller' => function ($cfg, $messages, $overrides) use (&$calls) {
    $calls++;
    return ai_response('this is not JSON at all');
}));
$draft = $gen->build_draft('When someone asks about hotel booking, ask for dates and guests.', array('user_id' => 1), array());
is_false('generator: invalid JSON from the AI never yields ok=true', $draft['ok']);
is_same('generator: exactly one retry happened (2 calls total)', 2, $calls);
is_same('generator: no nodes are returned on failure', null, $draft['nodes']);
if ($draft['message'] === '') bad('generator: failure carries a message', '(empty)'); else ok('generator: failure carries a message (never a silent/empty failure)');

// second attempt succeeds after a first bad reply
$calls = 0;
$gen2 = new Flow_prompt_generator(array('ai_caller' => function ($cfg, $messages, $overrides) use (&$calls) {
    $calls++;
    if ($calls === 1) return ai_response('nope not json');
    return ai_response(json_encode(fixture_minimal()));
}));
$draft2 = $gen2->build_draft('Say hello.', array('user_id' => 1), array());
is_true('generator: recovers on the retry when the 2nd reply is valid', $draft2['ok']);
is_same('generator: two calls were made (1 bad + 1 corrected)', 2, $calls);

// ---------------------------------------------------------------- generator: CRM-deal fallback (never a real node)

$gen3 = new Flow_prompt_generator(array('ai_caller' => function ($cfg, $messages, $overrides) {
    $f = fixture_minimal();
    // A misbehaving AI tries to smuggle "create a CRM deal" semantics onto the reference node.
    $f['nodes']['1']['data']['crm_deal_request'] = true;
    return ai_response(json_encode($f));
}));
$draft3 = $gen3->build_draft('When someone books, save the lead as a CRM deal.', array('user_id' => 1), array());
is_true('generator: a CRM-deal request still yields a usable draft', $draft3['ok']);
if ($draft3['ok']) {
    is_true('  ...the marker field was stripped', !array_key_exists('crm_deal_request', $draft3['nodes']['1']['data']));
    is_true('  ...and replaced with a "Lead" label', in_array('Lead', $draft3['nodes']['1']['data']['labelIds'], true));
}
if (empty($draft3['substitutions'])) bad('generator: the CRM-deal substitution is reported, not hidden', '(substitutions empty)');
else { ok('generator: the CRM-deal substitution is reported, not hidden'); contains('  ...mentioning the Lead label', $draft3['substitutions'][0], 'Lead'); }

// ---------------------------------------------------------------- generator: unowned httpApi fallback (never invents a price)

$gen4 = new Flow_prompt_generator(array('ai_caller' => function ($cfg, $messages, $overrides) {
    return ai_response(json_encode(fixture_hotel('httpApi'))); // references httpApiId 42
}));
$draft4 = $gen4->build_draft('Hotel booking with price lookup.', array('user_id' => 1), array()); // tenant owns NO http api
is_true('generator: an unowned httpApi id still yields a usable draft', $draft4['ok']);
if ($draft4['ok']) {
    $node6 = $draft4['nodes']['6'];
    is_true('  ...node was downgraded away from httpApi', !isset($node6['inputs']['httpApiInput']));
    is_true('  ...node is now a text node with the documented placeholder', isset($node6['data']['textMessage']) && strpos($node6['data']['textMessage'], 'connect this to Products & Pricing') !== false);
    is_true('  ...the upstream connection into it was fixed to textInput too', $draft4['nodes']['5']['outputs']['userInputFlowSingleOutput']['connections'][0]['input'] === 'textInput');
}
if (empty($draft4['substitutions'])) bad('generator: the price-node substitution is reported, not hidden', '(substitutions empty)');
else ok('generator: the price-node substitution is reported, not hidden');

// happy path: tenant DOES own that httpApi id -> no substitution needed
$gen5 = new Flow_prompt_generator(array('ai_caller' => function ($cfg, $messages, $overrides) {
    return ai_response(json_encode(fixture_hotel('httpApi')));
}));
$draft5 = $gen5->build_draft('Hotel booking with price lookup.', array('user_id' => 1), array(42));
is_true('generator: an owned httpApi id needs no substitution', $draft5['ok']);
is_same('  ...substitutions stayed empty', array(), $draft5['substitutions']);
is_true('  ...node 6 is still the httpApi node', isset($draft5['nodes']['6']['data']['httpApiId']));

// ---------------------------------------------------------------- generator: fresh unique ids, always

$gen6 = new Flow_prompt_generator(array('ai_caller' => function ($cfg, $messages, $overrides) {
    return ai_response(json_encode(fixture_minimal()));
}));
$draft6 = $gen6->build_draft('Say hello.', array('user_id' => 1), array());
is_true('generator: node 1 xitFbpostbackId was regenerated away from the AI-chosen value', $draft6['nodes']['1']['data']['xitFbpostbackId'] !== 'REF1');
is_true('generator: node 2 uniqueId was regenerated away from the AI-chosen value', $draft6['nodes']['2']['data']['uniqueId'] !== 'U1');

// ---------------------------------------------------------------- generator: blank instruction fails fast, no AI call

$calls7 = 0;
$gen7 = new Flow_prompt_generator(array('ai_caller' => function () use (&$calls7) { $calls7++; return ai_response('{}'); }));
$draft7 = $gen7->build_draft('   ', array('user_id' => 1), array());
is_false('generator: a blank instruction is rejected', $draft7['ok']);
is_same('generator: the AI is never called for a blank instruction', 0, $calls7);

echo "== $PASS passed, $FAIL failed ==\n";
exit($FAIL === 0 ? 0 : 1);
