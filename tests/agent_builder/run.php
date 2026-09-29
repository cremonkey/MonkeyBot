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
same('t_create_booking: source recorded as ai', 'ai', $row['source']);

$r_clash = $tools->execute('t_create_booking', array('service' => 'Haircut', 'date' => '2026-10-10', 'time' => '10:00', 'name' => 'Other'), array('user_id' => 100, 'capabilities' => array('t_create_booking')));
check('t_create_booking: double-booking the same slot is rejected', stripos($r_clash, 'Booking failed') === 0);
same('t_create_booking: clash did not insert a second row', 1, count($CI->db->tables['ab_appointments']));

echo "== $PASS passed, $FAIL failed ==\n";
exit($FAIL ? 1 : 0);
