<?php
/**
 * Task 27 (C12) — AI Employee Builder unit tests. DB-free: get_instance() returns a tiny
 * fake CI whose ->db is an in-memory stand-in for the handful of query-builder calls the
 * code under test issues, and whose ->basic/->load are minimal recorders/no-ops.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/agent_builder/run.php
 *
 * Covers:
 *  1. REVIEW GATE — NULL capabilities_json/restrictions_json: Ai_tools::catalog() returns
 *     the exact same list as before this task, and ai_agent_enforce_restrictions() is a
 *     pure no-op (identical text, never blocks).
 *  2. Per-tool enablement: capabilities_json filters the catalog; t_create_booking is never
 *     offered unless explicitly listed, even with an empty capabilities array.
 *  3. Restrictions: forbidden_topics -> handoff, max_discount -> handoff, allowed_link_domains
 *     strips a non-matching link and keeps a matching one.
 *  4. t_create_booking: tenant scope (a service from another user is rejected), vague input
 *     (missing service/date/time) is refused without touching the DB, and a valid booking
 *     inserts through the same helper the web widget uses.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
define('BASEPATH', dirname(__DIR__, 2) . '/system/');
define('APPPATH', dirname(__DIR__, 2) . '/application/');
date_default_timezone_set('UTC');

$PASS = 0; $FAIL = 0;
function ok($n) { global $PASS; $PASS++; echo "  ok    $n\n"; }
function bad($n, $why) { global $FAIL; $FAIL++; echo "  FAIL  $n\n        $why\n"; }
function check($n, $cond, $why = '') { $cond ? ok($n) : bad($n, $why === '' ? 'condition was false' : $why); }
function same($n, $want, $got) { $want === $got ? ok($n) : bad($n, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true)); }
function log_message($l, $m) { /* discard */ }

// ------------------------------------------------------------ fake CI

class FakeResult {
    private $rows;
    public function __construct($rows) { $this->rows = array_values($rows); }
    public function row_array() { return $this->rows[0] ?? null; }
    public function result_array() { return $this->rows; }
}

class FakeDb {
    public $tables = array();
    public $fields = array();
    private $auto = array();
    private $t; private $w = array(); private $lim = null; private $like = array();

    public function table_exists($t) { return isset($this->tables[$t]); }
    public function field_exists($f, $t) { return in_array($f, $this->fields[$t] ?? array(), true); }
    public function select($c = null) { return $this; }
    public function from($t) { $this->t = $t; return $this; }
    public function where($k, $v = null) { $this->w[] = array($k, $v); return $this; }
    public function where_in($k, $v) { $this->w[] = array($k, $v, 'in'); return $this; }
    public function like($k, $v) { $this->like[] = array($k, $v); return $this; }
    public function order_by($a = null, $b = null) { return $this; }
    public function limit($n) { $this->lim = $n; return $this; }
    private function reset() { $this->w = array(); $this->lim = null; $this->like = array(); }
    private function filter($rows, $conds, $likes) {
        foreach ($conds as $c) {
            if (isset($c[2]) && $c[2] === 'in') {
                $k = $c[0]; $v = $c[1];
                $rows = array_values(array_filter($rows, function ($r) use ($k, $v) { return array_key_exists($k, $r) && in_array((string) $r[$k], array_map('strval', $v), true); }));
                continue;
            }
            list($k, $v) = $c;
            if (strpos($k, ' <') !== false || strpos($k, ' >') !== false) {
                $col = trim(preg_replace('/[<>]=?$/', '', $k));
                $op = trim(str_replace($col, '', $k));
                $rows = array_values(array_filter($rows, function ($r) use ($col, $op, $v) {
                    if (!array_key_exists($col, $r)) return false;
                    return $op === '<' ? ($r[$col] < $v) : ($r[$col] > $v);
                }));
                continue;
            }
            $rows = array_values(array_filter($rows, function ($r) use ($k, $v) { return array_key_exists($k, $r) && (string) $r[$k] === (string) $v; }));
        }
        foreach ($likes as $l) {
            list($k, $v) = $l;
            $rows = array_values(array_filter($rows, function ($r) use ($k, $v) { return array_key_exists($k, $r) && stripos((string) $r[$k], (string) $v) !== false; }));
        }
        return $rows;
    }
    public function get() {
        $rows = $this->filter(array_values($this->tables[$this->t] ?? array()), $this->w, $this->like);
        if ($this->lim !== null) $rows = array_slice($rows, 0, $this->lim);
        $this->reset();
        return new FakeResult($rows);
    }
    public function count_all_results() { return count($this->get()->result_array()); }
    private $last_insert_id = 0;
    public function insert($t, $data) {
        if (!isset($this->auto[$t])) $this->auto[$t] = count($this->tables[$t] ?? array()) + 1;
        $id = $this->auto[$t]++; $data['id'] = $id; $this->tables[$t][$id] = $data;
        $this->last_insert_id = $id; $this->reset();
        return true;
    }
    public function insert_id() { return $this->last_insert_id; }
}

class FakeBasic {
    public $db;
    public function __construct($db) { $this->db = $db; }
    public function insert_data($table, $data) { $this->db->insert($table, $data); return $this->db->insert_id(); }
}

class FakeLoader {
    public function helper($h) { return true; }
    public function library($l) { return true; }
}

class FakeSession { public function userdata($k) { return null; } }

class FakeAiToolsCalls {
    public $calls = array();
    public function execute($name, $args, $ctx) { $this->calls[] = array($name, $args, $ctx); return 'ok'; }
}

final class StubCI {
    public $db; public $load; public $basic; public $session; public $ai_tools;
    public function __construct() {
        $this->db = new FakeDb();
        $this->load = new FakeLoader();
        $this->basic = new FakeBasic($this->db);
        $this->session = new FakeSession();
        $this->ai_tools = new FakeAiToolsCalls();
    }
}
$GLOBALS['__stub_ci'] = new StubCI();
function &get_instance() { return $GLOBALS['__stub_ci']; }
/** @var StubCI $CI */
$CI = $GLOBALS['__stub_ci'];

require APPPATH . 'helpers/ai_agent_helper.php';
require APPPATH . 'helpers/ai_safety_helper.php';
require APPPATH . 'helpers/media_helper.php';
require APPPATH . 'helpers/appointment_booking_helper.php';
require APPPATH . 'libraries/Ai_tools.php';

// ============================================================ 1. capabilities/restrictions decode

same('capabilities: missing column/key -> null', null, ai_agent_capabilities(array('name' => 'x')));
same('capabilities: empty string -> null', null, ai_agent_capabilities(array('capabilities_json' => '')));
same('capabilities: invalid json -> null', null, ai_agent_capabilities(array('capabilities_json' => '{not json')));
same('capabilities: bare array', array('a', 'b'), ai_agent_capabilities(array('capabilities_json' => json_encode(array('a', 'b')))));
same('capabilities: {"tools":[...]} tolerated', array('a'), ai_agent_capabilities(array('capabilities_json' => json_encode(array('tools' => array('a'))))));
same('capabilities: empty array is NOT null (means "no tools")', array(), ai_agent_capabilities(array('capabilities_json' => json_encode(array()))));

$r0 = ai_agent_restrictions(array());
same('restrictions: no column -> empty defaults', array('allowed_link_domains' => array(), 'max_discount' => null, 'forbidden_topics' => array(), 'escalation_rules' => ''), $r0);
$r1 = ai_agent_restrictions(array('restrictions_json' => json_encode(array('max_discount' => 15, 'forbidden_topics' => array('Medical'), 'allowed_link_domains' => array('Example.com')))));
same('restrictions: max_discount decoded', 15.0, $r1['max_discount']);
same('restrictions: forbidden_topics decoded', array('Medical'), $r1['forbidden_topics']);
same('restrictions: allowed_link_domains lowercased', array('example.com'), $r1['allowed_link_domains']);

// ============================================================ 2. REVIEW GATE — Ai_tools::catalog()

$tools = new Ai_tools();
$default_no_context = array_column($tools->catalog(null), 'name');
$default_capabilities_null = array_column($tools->catalog(array('user_id' => 1, 'capabilities' => null)), 'name');
same('golden: catalog(null) unchanged base set', array('search_products', 'get_order_status', 'create_discount_code', 'handoff_to_human', 'save_lead_to_crm'), $default_no_context);
same('golden: capabilities NULL == capabilities absent (identical tool list)', $default_no_context, $default_capabilities_null);
check('golden: t_create_booking never appears without capabilities', !in_array('t_create_booking', $default_no_context, true));

$filtered = array_column($tools->catalog(array('capabilities' => array('search_products', 'handoff_to_human'))), 'name');
same('capabilities filter: only listed tools offered', array('search_products', 'handoff_to_human'), $filtered);

$empty_caps = array_column($tools->catalog(array('capabilities' => array())), 'name');
same('capabilities filter: empty array -> zero tools', array(), $empty_caps);

// t_create_booking only appears when explicitly enabled AND ab_services exists
$CI->db->tables['ab_services'] = array();
$with_booking = array_column($tools->catalog(array('capabilities' => array('t_create_booking'))), 'name');
same('t_create_booking offered only when explicitly enabled', array('t_create_booking'), $with_booking);
$without_ab_services = $tools; // same instance, but pull table away
unset($CI->db->tables['ab_services']);
$no_table = array_column($tools->catalog(array('capabilities' => array('t_create_booking'))), 'name');
same('t_create_booking withheld when ab_services table missing', array(), $no_table);
$CI->db->tables['ab_services'] = array(); // restore for later tests

// ============================================================ 3. restrictions enforcement (golden + each rule)

$CI->db->fields['ai_agent_profiles'] = array('role', 'goal', 'tone', 'capabilities_json', 'restrictions_json');

$profile_no_restrictions = array('id' => 1);
$gate = ai_agent_enforce_restrictions($profile_no_restrictions, 1, 'fb', '10', 's1', 'hi', 'Some reply with 40% off');
same('golden: no restrictions_json -> text untouched', 'Some reply with 40% off', $gate['text']);
check('golden: no restrictions_json -> never blocked', $gate['blocked'] === false);

$profile_topic = array('id' => 2, 'restrictions_json' => json_encode(array('forbidden_topics' => array('refund'))));
$g1 = ai_agent_enforce_restrictions($profile_topic, 1, 'fb', '10', 's1', 'I want a refund please', 'Sure, here is how refunds work.');
check('forbidden_topics: blocks and hands off', $g1['blocked'] === true && strpos($g1['reason'], 'forbidden_topic') === 0);

$profile_discount = array('id' => 3, 'restrictions_json' => json_encode(array('max_discount' => 10)));
$g2 = ai_agent_enforce_restrictions($profile_discount, 1, 'fb', '10', 's1', 'any discount?', 'Sure, I can give you 25% off today!');
check('max_discount: reply above ceiling is handed off', $g2['blocked'] === true && strpos($g2['reason'], 'max_discount') === 0);
$g2b = ai_agent_enforce_restrictions($profile_discount, 1, 'fb', '10', 's1', 'any discount?', 'Sure, 5% off for you.');
check('max_discount: reply within ceiling passes through', $g2b['blocked'] === false && $g2b['text'] === 'Sure, 5% off for you.');

$profile_links = array('id' => 4, 'restrictions_json' => json_encode(array('allowed_link_domains' => array('example.com'))));
$g3 = ai_agent_enforce_restrictions($profile_links, 1, 'fb', '10', 's1', 'send me a link', 'Here: https://evil.example.net/x and https://example.com/gallery');
check('allowed_link_domains: non-matching link stripped', strpos($g3['text'], 'evil.example.net') === false);
check('allowed_link_domains: matching link kept', strpos($g3['text'], 'https://example.com/gallery') !== false);
check('allowed_link_domains: never a hand-off by itself', $g3['blocked'] === false);

// ============================================================ 4. t_create_booking

$CI->db->tables['ab_services'] = array(
    1 => array('id' => 1, 'user_id' => 100, 'name' => 'Haircut', 'duration_min' => 30, 'status' => '1'),
    2 => array('id' => 2, 'user_id' => 200, 'name' => 'Massage', 'duration_min' => 60, 'status' => '1'),
);
$CI->db->tables['ab_appointments'] = array();

$r_vague = $tools->execute('t_create_booking', array('service' => 'Haircut'), array('user_id' => 100, 'capabilities' => array('t_create_booking')));
check('t_create_booking: vague input (no date/time) refused, nothing booked', empty($CI->db->tables['ab_appointments']) && stripos($r_vague, 'ask the customer') !== false);

$r_wrong_tenant = $tools->execute('t_create_booking', array('service' => 'Massage', 'date' => '2026-10-10', 'time' => '10:00'), array('user_id' => 100, 'capabilities' => array('t_create_booking')));
check('t_create_booking: tenant scope — another tenant\'s service is unknown here', empty($CI->db->tables['ab_appointments']) && stripos($r_wrong_tenant, 'No matching service') !== false);

$r_not_offered = $tools->execute('t_create_booking', array('service' => 'Haircut', 'date' => '2026-10-10', 'time' => '10:00'), array('user_id' => 100, 'capabilities' => array('search_products')));
same('t_create_booking: execute() refuses when not in capabilities (defense in depth)', 'Error: unknown tool.', $r_not_offered);
check('t_create_booking: refusal did not book anything', empty($CI->db->tables['ab_appointments']));

$r_ok = $tools->execute('t_create_booking', array('service' => 'Haircut', 'date' => '2026-10-10', 'time' => '10:00', 'name' => 'Amr', 'phone' => '0100'), array('user_id' => 100, 'capabilities' => array('t_create_booking')));
check('t_create_booking: valid explicit booking succeeds', stripos($r_ok, 'Booked Haircut') === 0);
same('t_create_booking: exactly one appointment row inserted', 1, count($CI->db->tables['ab_appointments']));
$row = array_values($CI->db->tables['ab_appointments'])[0];
same('t_create_booking: inserted row is tenant-scoped', 100, $row['user_id']);

same('t_create_booking: source recorded as the real "chat" enum value', 'chat', $row['source']);

$r_clash = $tools->execute('t_create_booking', array('service' => 'Haircut', 'date' => '2026-10-10', 'time' => '10:00', 'name' => 'Other'), array('user_id' => 100, 'capabilities' => array('t_create_booking')));
check('t_create_booking: double-booking the same slot is rejected', stripos($r_clash, 'already booked') !== false);
same('t_create_booking: clash did not insert a second row', 1, count($CI->db->tables['ab_appointments']));

// ambiguous fuzzy match: several services contain the typed fragment -> ask, book nothing
$CI->db->tables['ab_services'][3] = array('id' => 3, 'user_id' => 100, 'name' => 'Deep Massage', 'duration_min' => 45, 'status' => '1');
$CI->db->tables['ab_services'][4] = array('id' => 4, 'user_id' => 100, 'name' => 'Quick Massage', 'duration_min' => 15, 'status' => '1');
$before = count($CI->db->tables['ab_appointments']);
$r_ambiguous = $tools->execute('t_create_booking', array('service' => 'Massage', 'date' => '2026-10-11', 'time' => '11:00', 'name' => 'X'), array('user_id' => 100, 'capabilities' => array('t_create_booking')));
check('t_create_booking: ambiguous fuzzy match asks instead of guessing', stripos($r_ambiguous, 'Several services match') === 0);
check('t_create_booking: ambiguous match books nothing', count($CI->db->tables['ab_appointments']) === $before);

// impossible/invalid dates and times: checkdate()/range rejects them BEFORE strtotime can
// silently roll them over into 1970-01-01 or some other wrong slot. Web-widget path is
// untouched by this — see the golden submit_booking tests below.
foreach (array(
    array('2026-13-45', '10:00', 'month 13 / day 45 (calendar-impossible)'),
    array('2026-02-30', '10:00', 'Feb 30 (calendar-impossible)'),
    array('2026-10-10', '25:00', 'hour 25 (out of range)'),
    array('2020-01-01', '10:00', 'a datetime already in the past'),
) as $case) {
    list($d, $t, $label) = $case;
    $before_n = count($CI->db->tables['ab_appointments']);
    $r = $tools->execute('t_create_booking', array('service' => 'Haircut', 'date' => $d, 'time' => $t, 'name' => 'X'), array('user_id' => 100, 'capabilities' => array('t_create_booking')));
    check("t_create_booking: rejects $label", stripos($r, 'not valid') !== false);
    check("t_create_booking: $label books nothing (no 1970-01-01 row)", count($CI->db->tables['ab_appointments']) === $before_n);
}

// ============================================================ 5. ab_appointments.source enum — read from the real migration so this can't drift
$spec17 = file_get_contents(dirname(__DIR__, 2) . '/assets/backup_db/migrations/2026-07-05-spec17.sql');
preg_match("/source ENUM\\(([^)]+)\\)/", $spec17, $em);
check('found the source ENUM definition in spec17.sql', !empty($em));
$real_enum = array_map(function ($v) { return trim($v, " '"); }, explode(',', $em[1]));
same('ab_appointments.source enum is exactly chat/web/manual', array('chat', 'web', 'manual'), $real_enum);
check('t_create_booking wrote a value that is actually in the enum', in_array($row['source'], $real_enum, true));

// ============================================================ 6. web widget byte-identical golden test
//
// Reimplements Appointment_booking::submit_booking()'s pre-C12 logic (captured from
// `git show a6cfffc9^:application/controllers/Appointment_booking.php`) so this test can
// assert the CURRENT controller produces byte-identical JSON for the same inputs,
// including edge cases the shared helper must NOT get stricter about (whitespace-only
// name, no date/time format validation).
function legacy_submit_booking($CI, $uid, $service_id, $date, $time, $name, $phone, $email)
{
    $name = strip_tags((string) $name); $phone = strip_tags((string) $phone); $email = strip_tags((string) $email);
    if (!$uid || !$service_id || !$date || !$time || $name === '') return array('status' => '0', 'message' => 'Missing fields');
    $svc = $CI->db->from('ab_services')->where('id', $service_id)->where('user_id', $uid)->get()->row_array();
    if (!$svc) return array('status' => '0', 'message' => 'Invalid service');
    $starts = date('Y-m-d H:i:s', strtotime($date . ' ' . $time));
    $ends = date('Y-m-d H:i:s', strtotime($starts . ' +' . $svc['duration_min'] . ' minutes'));
    $clash = $CI->db->from('ab_appointments')->where('user_id', $uid)
        ->where('starts_at <', $ends)->where('ends_at >', $starts)
        ->where_in('status', array('pending', 'confirmed'))->count_all_results();
    if ($clash) return array('status' => '0', 'message' => 'That slot was just taken.');
    return array('status' => '1', 'message_prefix' => 'Booked for ' . $starts . '. Reference: ');
}

function current_submit_booking($CI, $uid, $service_id, $date, $time, $name, $phone, $email)
{
    $res = ab_create_booking($uid, $service_id, $date, $time, $name, $phone, $email, 'web');
    switch ($res['code']) {
        case 'missing_fields': return array('status' => '0', 'message' => 'Missing fields');
        case 'invalid_service': return array('status' => '0', 'message' => 'Invalid service');
        case 'clash': return array('status' => '0', 'message' => 'That slot was just taken.');
    }
    return array('status' => '1', 'message' => 'Booked for ' . $res['starts_at'] . '. Reference: ' . $res['booking_key']);
}

$CI->db->tables['ab_services'] = array(1 => array('id' => 1, 'user_id' => 300, 'name' => 'Consult', 'duration_min' => 20, 'status' => '1'));
$CI->db->tables['ab_appointments'] = array();

// missing fields
same('golden submit_booking: missing service_id', array('status' => '0', 'message' => 'Missing fields'),
    legacy_submit_booking($CI, 300, 0, '2026-11-01', '09:00', 'Amr', '', ''));
same('golden submit_booking: current matches legacy for missing fields', array('status' => '0', 'message' => 'Missing fields'),
    current_submit_booking($CI, 300, 0, '2026-11-01', '09:00', 'Amr', '', ''));

// whitespace-only name: the OLD code never trimmed, so "   " is NOT empty and passes through
$legacy_ws = legacy_submit_booking($CI, 300, 1, '2026-11-01', '09:00', '   ', '', '');
same('golden submit_booking: legacy accepts whitespace-only name (never trimmed)', '1', $legacy_ws['status']);
$CI->db->tables['ab_appointments'] = array(); // reset between the two runs — each inserts its own row
$current_ws = current_submit_booking($CI, 300, 1, '2026-11-01', '09:00', '   ', '', '');
same('golden submit_booking: current also accepts whitespace-only name (no new trim)', '1', $current_ws['status']);

// invalid service
$CI->db->tables['ab_appointments'] = array();
same('golden submit_booking: invalid service, legacy', array('status' => '0', 'message' => 'Invalid service'),
    legacy_submit_booking($CI, 300, 999, '2026-11-01', '09:00', 'Amr', '', ''));
same('golden submit_booking: invalid service, current — byte-identical message', array('status' => '0', 'message' => 'Invalid service'),
    current_submit_booking($CI, 300, 999, '2026-11-01', '09:00', 'Amr', '', ''));

// happy path — same $starts/$ends derivation (no date/time regex in either path)
$CI->db->tables['ab_appointments'] = array();
$legacy_ok = legacy_submit_booking($CI, 300, 1, '2026-11-01', '09:00', 'Amr', '01000000000', '');
check('golden submit_booking: legacy happy path books', $legacy_ok['status'] === '1');
$CI->db->tables['ab_appointments'] = array();
$current_ok = current_submit_booking($CI, 300, 1, '2026-11-01', '09:00', 'Amr', '01000000000', '');
same('golden submit_booking: current happy-path message is byte-identical to legacy',
    $legacy_ok['message_prefix'] . array_values($CI->db->tables['ab_appointments'])[0]['booking_key'], $current_ok['message']);
same('golden submit_booking: current inserts source=web (unchanged)', 'web', array_values($CI->db->tables['ab_appointments'])[0]['source']);

// clash — the EXACT legacy wording "That slot was just taken." must survive
$clash_row = array_values($CI->db->tables['ab_appointments'])[0];
same('golden submit_booking: legacy clash message', array('status' => '0', 'message' => 'That slot was just taken.'),
    legacy_submit_booking($CI, 300, 1, '2026-11-01', '09:00', 'Someone Else', '', ''));
same('golden submit_booking: current clash message is byte-identical to legacy', array('status' => '0', 'message' => 'That slot was just taken.'),
    current_submit_booking($CI, 300, 1, '2026-11-01', '09:00', 'Someone Else', '', ''));

echo "== $PASS passed, $FAIL failed ==\n";
exit($FAIL ? 1 : 0);
