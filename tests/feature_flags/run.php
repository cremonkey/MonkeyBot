<?php
/**
 * Per-tenant feature flags (Task 10 / brief B2, §50) unit tests. No DB, no CodeIgniter
 * bootstrap, no network — exercises application/helpers/feature_flag_helper.php against a
 * fake query builder/config, plus static checks that Agency_flags.php is admin-gated,
 * CSRF-checked and validates the client id, and that Agency.php actually wires the helper in.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/feature_flags/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
// get_instance() normally throws — every helper function under test must work with no CI
// instance at all. feature_on() is the one exception: it explicitly calls get_instance() to
// find $this->db/$this->config, so tests for it set $GLOBALS['FAKE_CI'] first to get a fake
// CI object back instead of the throw.
function get_instance()
{
    if (isset($GLOBALS['FAKE_CI'])) return $GLOBALS['FAKE_CI'];
    throw new Exception('feature_flag_helper.php must not need a CI instance');
}

require __DIR__ . '/../../application/helpers/feature_flag_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ------------------------------------------------------------------------------------------
// Fake CI DB: an in-memory feature_flags table keyed by "uid|flag", plus a query log so tests
// can assert caching (one SELECT per (uid,flag), not one per call) and the insert/update/delete
// shape feature_flag_set() actually issues.
class FakeFlagsDB
{
    public $table_exists = true;
    public $rows = array();     // "uid|flag" => enabled (0/1)
    public $selects = 0;
    public $inserts = array();
    public $updates = array();
    public $deletes = array();

    private $wheres = array();

    public function table_exists($t) { return $t === 'feature_flags' ? $this->table_exists : false; }

    public function select($s) { return $this; }
    public function from($t) { return $this; }
    public function where($a, $b = null) { $this->wheres[] = array($a, $b); return $this; }

    public function get()
    {
        $this->selects++;
        $uid = null; $flag = null;
        foreach ($this->wheres as $w) {
            if ($w[0] === 'user_id') $uid = $w[1];
            if ($w[0] === 'flag') $flag = $w[1];
        }
        $this->wheres = array();
        if ($uid !== null && $flag !== null) {
            $key = $uid . '|' . $flag;
            $one = array_key_exists($key, $this->rows) ? array('user_id' => (string) $uid, 'flag' => $flag, 'enabled' => (string) $this->rows[$key]) : array();
            return new FakeFlagsResult($one, $one ? array($one) : array());
        }
        // no uid filter -> "all rows" query (feature_flags_all_by_user)
        $all = array();
        foreach ($this->rows as $key => $enabled) {
            list($u, $f) = explode('|', $key, 2);
            $all[] = array('user_id' => $u, 'flag' => $f, 'enabled' => (string) $enabled);
        }
        return new FakeFlagsResult(array(), $all);
    }

    public function update($table, $data)
    {
        $uid = null; $flag = null;
        foreach ($this->wheres as $w) { if ($w[0] === 'user_id') $uid = $w[1]; if ($w[0] === 'flag') $flag = $w[1]; }
        $this->wheres = array();
        $this->rows[$uid . '|' . $flag] = $data['enabled'];
        $this->updates[] = array('uid' => $uid, 'flag' => $flag, 'data' => $data);
    }

    public function insert($table, $data)
    {
        $this->rows[$data['user_id'] . '|' . $data['flag']] = $data['enabled'];
        $this->inserts[] = $data;
    }

    public function delete($table)
    {
        $uid = null; $flag = null;
        foreach ($this->wheres as $w) { if ($w[0] === 'user_id') $uid = $w[1]; if ($w[0] === 'flag') $flag = $w[1]; }
        $this->wheres = array();
        unset($this->rows[$uid . '|' . $flag]);
        $this->deletes[] = array('uid' => $uid, 'flag' => $flag);
    }
}
class FakeFlagsResult
{
    private $row; private $rows;
    public function __construct($row, $rows) { $this->row = $row; $this->rows = $rows; }
    public function row_array() { return $this->row; }
    public function result_array() { return $this->rows; }
}
class FakeConfig
{
    public $items = array();
    public function item($k) { return array_key_exists($k, $this->items) ? $this->items[$k] : null; }
}

// ============================================================================================
echo "== precedence: no tenant row -> falls to global config item ==\n";
$db = new FakeFlagsDB();
$cfg = new FakeConfig();
$cfg->items['tiktok_dm_enabled'] = true;
check('config TRUE -> enabled', feature_enabled($db, $cfg, 'tiktok_dm_enabled', 7), true);

$db2 = new FakeFlagsDB(); $cfg2 = new FakeConfig(); $cfg2->items['x'] = '1';
check("config '1' (string) -> enabled", feature_enabled($db2, $cfg2, 'x', 7), true);

$db3 = new FakeFlagsDB(); $cfg3 = new FakeConfig(); $cfg3->items['x'] = 1;
check('config 1 (int) -> enabled', feature_enabled($db3, $cfg3, 'x', 7), true);

$db4 = new FakeFlagsDB(); $cfg4 = new FakeConfig(); $cfg4->items['x'] = false;
check('config FALSE -> disabled', feature_enabled($db4, $cfg4, 'x', 7), false);

$db5 = new FakeFlagsDB(); $cfg5 = new FakeConfig(); // unset config item
check('config unset -> disabled (FALSE), never a fatal', feature_enabled($db5, $cfg5, 'nonexistent_flag', 7), false);

echo "== precedence: a tenant row overrides the global config item, either direction ==\n";
$db6 = new FakeFlagsDB(); $db6->rows['7|tiktok_dm_enabled'] = 0;
$cfg6 = new FakeConfig(); $cfg6->items['tiktok_dm_enabled'] = true; // global ON
check('tenant override OFF beats global config ON', feature_enabled($db6, $cfg6, 'tiktok_dm_enabled', 7), false);

$db7 = new FakeFlagsDB(); $db7->rows['9|new_ai_feature'] = 1;
$cfg7 = new FakeConfig(); // global config item never set for this flag (FALSE by default)
check('tenant override ON beats an unset/FALSE global config', feature_enabled($db7, $cfg7, 'new_ai_feature', 9), true);

echo "== tenant rows are per-tenant: another user id on the same flag is untouched ==\n";
$db8 = new FakeFlagsDB(); $db8->rows['9|new_ai_feature'] = 1;
$cfg8 = new FakeConfig();
check('user 9 override does not leak to user 10', feature_enabled($db8, $cfg8, 'new_ai_feature', 10), false);

echo "== pre-migration: table_exists() FALSE -> behaves exactly like no override (config/FALSE) ==\n";
$db9 = new FakeFlagsDB(); $db9->table_exists = false; $db9->rows['7|x'] = 1; // would-be override, ignored
$cfg9 = new FakeConfig(); $cfg9->items['x'] = true;
check('pre-migration + config TRUE -> still reads config (table ignored)', feature_enabled($db9, $cfg9, 'x', 7), true);
$db10 = new FakeFlagsDB(); $db10->table_exists = false;
$cfg10 = new FakeConfig();
check('pre-migration + no config -> FALSE, not a crash', feature_enabled($db10, $cfg10, 'x', 7), false);

echo "== existing global flags keep working unchanged (no tenant override ever set) ==\n";
foreach (array('automation_engine_enabled', 'inbox_poll_enabled') as $flag) {
    $db11 = new FakeFlagsDB(); $cfg11 = new FakeConfig(); $cfg11->items[$flag] = true;
    check("$flag: unaffected by feature_enabled(), reads config exactly as before", feature_enabled($db11, $cfg11, $flag, 3), true);
}

echo "== caching: one SELECT per (db,uid,flag), not one per call ==\n";
$db12 = new FakeFlagsDB(); $db12->rows['7|x'] = 1; $cfg12 = new FakeConfig();
feature_enabled($db12, $cfg12, 'x', 7);
feature_enabled($db12, $cfg12, 'x', 7);
feature_enabled($db12, $cfg12, 'x', 7);
check('3 calls, same (db,uid,flag) -> exactly 1 SELECT', $db12->selects, 1);

// ============================================================================================
echo "== feature_flag_set(): on/off write, clear deletes, false before migration ==\n";
$db13 = new FakeFlagsDB();
check('set TRUE -> ok', feature_flag_set($db13, 7, 'x', true), true);
check('insert issued (no prior row)', count($db13->inserts), 1);
check('inserted enabled=1', $db13->inserts[0]['enabled'], 1);

feature_flag_set($db13, 7, 'x', false);
check('set FALSE on existing row -> update, not another insert', count($db13->updates), 1);
check('still only one insert total', count($db13->inserts), 1);
check('updated enabled=0', $db13->updates[0]['data']['enabled'], 0);

feature_flag_set($db13, 7, 'x', null);
check('clear -> delete issued', count($db13->deletes), 1);
check('row gone after clear', array_key_exists('7|x', $db13->rows), false);

$db14 = new FakeFlagsDB(); $db14->table_exists = false;
check('feature_flag_set before migration -> FALSE, no write attempted', feature_flag_set($db14, 7, 'x', true), false);
check('no insert attempted pre-migration', count($db14->inserts), 0);

echo "== feature_flags_all_by_user(): one query, grouped per user; empty pre-migration ==\n";
$db15 = new FakeFlagsDB();
$db15->rows['5|a'] = 1; $db15->rows['5|b'] = 0; $db15->rows['9|a'] = 1;
$got = feature_flags_all_by_user($db15);
check('grouped per user', $got, array(5 => array('a' => true, 'b' => false), 9 => array('a' => true)));

$db16 = new FakeFlagsDB(); $db16->table_exists = false;
check('feature_flags_all_by_user pre-migration -> empty array, not a crash', feature_flags_all_by_user($db16), array());

echo "== read-after-write in the same request sees the new value, not a stale cache ==\n";
$db17 = new FakeFlagsDB(); $cfg17 = new FakeConfig(); $cfg17->items['raw_flag'] = false;
check('initial read: no override, config FALSE -> disabled', feature_enabled($db17, $cfg17, 'raw_flag', 42), false);
feature_flag_set($db17, 42, 'raw_flag', true);
check('read right after set(TRUE) -> enabled, not the stale FALSE cached earlier', feature_enabled($db17, $cfg17, 'raw_flag', 42), true);
feature_flag_set($db17, 42, 'raw_flag', false);
check('read right after set(FALSE) -> disabled again', feature_enabled($db17, $cfg17, 'raw_flag', 42), false);
feature_flag_set($db17, 42, 'raw_flag', null);
check('read right after clear() -> falls back to config (FALSE), not the stale override', feature_enabled($db17, $cfg17, 'raw_flag', 42), false);
$db18 = new FakeFlagsDB(); $cfg18 = new FakeConfig(); $cfg18->items['other_flag'] = true;
feature_enabled($db18, $cfg18, 'other_flag', 1); // cache it
feature_flag_set($db18, 2, 'other_flag', true);  // unrelated uid — must not evict uid 1's entry
$cache_ref = &feature_flags_read_cache();
check('a write for one flag does not invalidate a different flag/uid cache entry',
    array_key_exists(spl_object_hash($db18) . '|1|other_flag', $cache_ref), true);

// ============================================================================================
echo "== feature_on(): the CI-friendly wrapper callers should use ==\n";
unset($GLOBALS['FAKE_CI']);
check('feature_on() with no CI bootstrapped -> FALSE, never throws', feature_on('anything', 5), false);

$fakeCI = new stdClass();
$fakeCI->db = new FakeFlagsDB();
$fakeCI->db->rows['5|new_ai_feature'] = 1;
$fakeCI->config = new FakeConfig();
$GLOBALS['FAKE_CI'] = $fakeCI;
check('feature_on() with a fake CI instance reads the tenant override via get_instance()', feature_on('new_ai_feature', 5), true);
check('feature_on() falls back to the fake CI config item when no override', feature_on('nope', 5), false);
$fakeCI->config->items['nope2'] = true; // distinct flag name: 'nope' above is now cached FALSE
check('feature_on() reads the fake CI config item TRUE', feature_on('nope2', 5), true);
unset($GLOBALS['FAKE_CI']);

$halfCI = new stdClass(); // missing ->config
$halfCI->db = new FakeFlagsDB();
$GLOBALS['FAKE_CI'] = $halfCI;
check('feature_on() with a CI object missing ->config -> FALSE, never a fatal', feature_on('x', 5), false);
unset($GLOBALS['FAKE_CI']);

// ============================================================================================
echo "== Agency_flags::toggle_flag() is admin-gated, CSRF-checked, and validates the client id ==\n";
$src = file_get_contents(__DIR__ . '/../../application/controllers/Agency_flags.php');
$ctor_start = strpos($src, 'function __construct');
$ctor = $ctor_start === false ? '' : substr($src, $ctor_start, (strpos($src, 'function index', $ctor_start) ?: strlen($src)) - $ctor_start);
check('logged-in gate present', strpos($ctor, "session->userdata('logged_in') != 1") !== false, true);
check('admin-only gate present', strpos($ctor, "session->userdata('user_type') != 'Admin'") !== false, true);
check('admin-only gate redirects to access_forbidden', strpos($ctor, "redirect('home/access_forbidden'") !== false, true);

$tf_start = strpos($src, 'function toggle_flag');
$toggle = $tf_start === false ? '' : substr($src, $tf_start, 4000);
check('toggle_flag found', $tf_start !== false, true);
check('toggle_flag calls ajax_check()', strpos($toggle, 'ajax_check()') !== false, true);
check('toggle_flag calls csrf_token_check()', strpos($toggle, 'csrf_token_check()') !== false, true);
check('toggle_flag validates the client id belongs to a real, non-deleted user',
    strpos($toggle, "where('deleted', '0')") !== false && strpos($toggle, "count_all_results('users')") !== false, true);
check('toggle_flag validates the flag name against a strict pattern', strpos($toggle, 'preg_match') !== false, true);
check('toggle_flag writes through feature_flag_set() (not raw SQL in the controller)', strpos($toggle, 'feature_flag_set(') !== false, true);

// ============================================================================================
echo "== Agency.php actually wires the flags helper into index() (not just written, called) ==\n";
$agency_src = file_get_contents(__DIR__ . '/../../application/controllers/Agency.php');
$idx_start = strpos($agency_src, 'function index');
$idx = $idx_start === false ? '' : substr($agency_src, $idx_start, 12000);
check('index() loads the feature_flag helper', strpos($idx, "helper('feature_flag')") !== false, true);
check('index() calls feature_flags_all_by_user()', strpos($idx, 'feature_flags_all_by_user(') !== false, true);
check('index() calls feature_flags_known()', strpos($idx, 'feature_flags_known(') !== false, true);
check('Agency.php stays read-only: no ->insert(/->update(/->delete( in the file', preg_match('/->(insert|update|delete)\s*\(/', $agency_src), 0);

echo "== $pass passed, $fail failed ==\n";
exit($fail ? 1 : 0);
