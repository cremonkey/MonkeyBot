<?php
/**
 * Agency Control Center accuracy (task A5) unit tests. No DB, no CI bootstrap, no network —
 * exercises application/helpers/agency_health_helper.php against a fake query builder, and
 * statically checks the admin gate in Agency.php is still intact.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/agency/run.php
 *
 * The "numbers match direct SQL" acceptance check (brief A5) is covered by these unit tests
 * only — no docker/scripts/staging_agency_tests.sh exists; see task-5-report.md for why.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('agency_health_helper.php must not need a CI instance'); }

require __DIR__ . '/../../application/helpers/agency_health_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ------------------------------------------------------------------------------------------
// Fake CI query builder: table_exists()/field_exists() are configurable per test, and each
// select()/from()/where()/group_by()/get() chain returns the next canned result set queued
// for that table. Records the query "shape" (columns selected, where filters, group_by) so a
// test can assert the aggregation is ONE grouped query per table, not N queries per client.
class FakeAgencyDB
{
    public $tables = array();      // table => bool
    public $fields = array();      // "table.col" => bool
    public $queued = array();      // table => array of result_array() to return, in order
    public $queries = array();     // recorded shape of every get()

    private $select; private $from; private $wheres = array(); private $where_ins = array(); private $group;

    public function table_exists($t) { return !empty($this->tables[$t]); }
    public function field_exists($c, $t) { return !empty($this->fields[$t . '.' . $c]); }

    public function select($s) { $this->select = $s; return $this; }
    public function from($t) { $this->from = $t; return $this; }
    public function where($a, $b = null) { $this->wheres[] = array($a, $b); return $this; }
    public function where_in($col, $vals) { $this->where_ins[] = array($col, $vals); return $this; }
    public function group_by($g) { $this->group = $g; return $this; }

    public function get()
    {
        $this->queries[] = array(
            'from' => $this->from, 'select' => $this->select, 'where' => $this->wheres,
            'where_in' => $this->where_ins, 'group_by' => $this->group,
        );
        $rows = isset($this->queued[$this->from]) && count($this->queued[$this->from])
            ? array_shift($this->queued[$this->from]) : array();
        $this->select = null; $this->from = null; $this->wheres = array(); $this->where_ins = array(); $this->group = null;
        return new FakeAgencyResult($rows);
    }
}
class FakeAgencyResult
{
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function result_array() { return $this->rows; }
}

// ============================================================================================
echo "== waiting: real handoff state, not the legacy bot-pause timer ==\n";

$db = new FakeAgencyDB();
$db->tables['conversations'] = true;
$db->fields['conversations.user_id'] = true;
$db->fields['conversations.handoff_state'] = true;
$db->queued['conversations'] = array(array(
    array('user_id' => '5', 'c' => '3'),
    array('user_id' => '9', 'c' => '1'),
));
$got = agency_health_waiting_by_user($db);
check('waiting_by_user aggregates per client', $got, array(5 => 3, 9 => 1));
check('waiting_by_user: exactly one query (grouped, not N per client)', count($db->queries), 1);
check('waiting_by_user filters on handoff_state=WAITING_FOR_HUMAN', $db->queries[0]['where'], array(array('handoff_state', 'WAITING_FOR_HUMAN')));

echo "== waiting: table missing -> empty, not a crash (table_exists guard) ==\n";
$db2 = new FakeAgencyDB();
check('no conversations table -> empty array', agency_health_waiting_by_user($db2), array());
check('no conversations table -> no query attempted', count($db2->queries), 0);

echo "== table_exists()/field_exists() result is cached, not re-checked per call ==\n";
$db3 = new FakeAgencyDB();
$calls = 0;
class CountingDB extends FakeAgencyDB {
    public $te_calls = 0;
    public function table_exists($t) { $this->te_calls++; return parent::table_exists($t); }
}
$db3 = new CountingDB();
$db3->tables['conversations'] = true;
$db3->fields['conversations.user_id'] = true;
$db3->fields['conversations.handoff_state'] = true;
$db3->queued['conversations'] = array(array(), array());
agency_health_waiting_by_user($db3);
agency_health_waiting_by_user($db3);
// agency_health_table_ready() caches by (table, cols) inside a `static` array, so the second
// call to the SAME column set must not re-hit table_exists().
check('table_exists() checked once, cached for the second call', $db3->te_calls, 1);

echo "== CRM push queue: pending/failed counts + last failure time, per client ==\n";
$db4 = new FakeAgencyDB();
$db4->tables['crm_external_log'] = true;
$db4->fields['crm_external_log.user_id'] = true;
$db4->fields['crm_external_log.status'] = true;
$db4->fields['crm_external_log.created_at'] = true;
$db4->queued['crm_external_log'] = array(
    array(array('user_id' => '5', 'status' => 'pending', 'c' => '2'), array('user_id' => '5', 'status' => 'failed', 'c' => '1')),
    array(array('user_id' => '5', 'm' => '2026-09-20 10:00:00')),
);
$got = agency_health_crm_push_by_user($db4);
check('crm push: pending+failed+last_failure for user 5', $got[5], array('pending' => 2, 'failed' => 1, 'last_failure' => '2026-09-20 10:00:00'));
check('crm push: user with no rows at all is absent (caller defaults it)', isset($got[9]), false);
check('crm push: exactly two grouped queries (counts, then last-failure time)', count($db4->queries), 2);

echo "== CRM push queue: missing table -> empty ==\n";
check('no crm_external_log table -> empty array', agency_health_crm_push_by_user(new FakeAgencyDB()), array());

echo "== Sheets sync: failing flag from crm_sheet_config.last_error ==\n";
$db5 = new FakeAgencyDB();
$db5->tables['crm_sheet_config'] = true;
$db5->fields['crm_sheet_config.user_id'] = true;
$db5->fields['crm_sheet_config.status'] = true;
$db5->fields['crm_sheet_config.last_error'] = true;
$db5->fields['crm_sheet_config.last_synced_at'] = true;
$db5->queued['crm_sheet_config'] = array(array(
    array('user_id' => '5', 'last_error' => 'Append failed (HTTP 403): forbidden', 'last_synced_at' => '2026-09-18 09:00:00'),
    array('user_id' => '9', 'last_error' => null, 'last_synced_at' => '2026-09-24 09:00:00'),
));
$got = agency_health_sheets_failures_by_user($db5);
check('sheets: user with last_error is failing', $got[5]['failing'], true);
check('sheets: user without last_error is not failing', $got[9]['failing'], false);
check('sheets: exactly one query', count($db5->queries), 1);

echo "== last inbound per platform: MAX(conversation_time), sender=user, grouped by user+platform ==\n";
$db6 = new FakeAgencyDB();
$db6->tables['livechat_messages'] = true;
$db6->fields['livechat_messages.user_id'] = true;
$db6->fields['livechat_messages.platform'] = true;
$db6->fields['livechat_messages.sender'] = true;
$db6->fields['livechat_messages.conversation_time'] = true;
$db6->queued['livechat_messages'] = array(array(
    array('user_id' => '5', 'platform' => 'fb', 'm' => '2026-09-24 08:00:00'),
    array('user_id' => '5', 'platform' => 'ig', 'm' => '2026-09-10 08:00:00'),
));
$got = agency_health_last_inbound_by_platform($db6);
check('last inbound: per-platform breakdown for one client', $got[5], array('fb' => '2026-09-24 08:00:00', 'ig' => '2026-09-10 08:00:00'));
check('last inbound: filters sender=user (customer messages only)', $db6->queries[0]['where'], array(array('sender', 'user')));
check('last inbound: exactly one query', count($db6->queries), 1);

// ============================================================================================
echo "== admin gate is intact (Agency::__construct) ==\n";
$src = file_get_contents(__DIR__ . '/../../application/controllers/Agency.php');
$ctor_start = strpos($src, 'function __construct');
$ctor = $ctor_start === false ? '' : substr($src, $ctor_start, (strpos($src, 'function index', $ctor_start) ?: strlen($src)) - $ctor_start);
if ($ctor_start === false) { bad('__construct found', 'missing'); } else { ok('__construct found'); }
check('logged-in gate present', strpos($ctor, "session->userdata('logged_in') != 1") !== false, true);
check('logged-in gate redirects to login', strpos($ctor, "redirect('home/login_page'") !== false, true);
check('admin-only gate present', strpos($ctor, "session->userdata('user_type') != 'Admin'") !== false, true);
check('admin-only gate redirects to access_forbidden', strpos($ctor, "redirect('home/access_forbidden'") !== false, true);

echo "== index() wires the new health helpers in (not just written, actually called) ==\n";
$idx_start = strpos($src, 'function index');
$idx = $idx_start === false ? '' : substr($src, $idx_start, 4000);
check('index() calls agency_health_waiting_by_user()', strpos($idx, 'agency_health_waiting_by_user(') !== false, true);
check('index() calls agency_health_crm_push_by_user()', strpos($idx, 'agency_health_crm_push_by_user(') !== false, true);
check('index() calls agency_health_sheets_failures_by_user()', strpos($idx, 'agency_health_sheets_failures_by_user(') !== false, true);
check('index() calls agency_health_last_inbound_by_platform()', strpos($idx, 'agency_health_last_inbound_by_platform(') !== false, true);
check('the old bot_paused_until count is kept, renamed to a legacy field', strpos($src, 'waiting_paused_legacy') !== false, true);

echo "== $pass passed, $fail failed ==\n";
exit($fail ? 1 : 0);
