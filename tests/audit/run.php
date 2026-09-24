<?php
/**
 * General audit log unit tests (brief §40). No CodeIgniter, no database, no network.
 *
 *   php tests/audit/run.php
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/audit/run.php
 *
 * Covers: secret stripping (audit_strip_secrets/audit_encode), audit_record()'s never-throws
 * contract and the row it writes through a fake CI instance, audit_log_list()'s tenant
 * scoping (cross-tenant guard, same FakeQB pattern as tests/inbox/run.php), and a static
 * wiring check that every call site the brief lists actually calls audit_record(.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
date_default_timezone_set('UTC');

require __DIR__ . '/../../application/helpers/audit_helper.php';

$PASS = 0; $FAIL = 0;
function ok($name) { global $PASS; $PASS++; echo "PASS  $name\n"; }
function bad($name, $why) { global $FAIL; $FAIL++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}
function is_true($name, $v) { check($name, true, $v); }

// ============================================================================================
echo "== audit_strip_secrets / audit_encode ==\n";

check('strips a top-level access_token', audit_strip_secrets(array('access_token' => 'abc123', 'label' => 'x')),
    array('access_token' => '***', 'label' => 'x'));
check('strips password, secret, api_key (case-insensitive) and leaves other keys', audit_strip_secrets(array(
    'Password' => 'p', 'app_secret' => 's', 'API_KEY' => 'k', 'webhook_secret' => 'w', 'name' => 'ok',
)), array('Password' => '***', 'app_secret' => '***', 'API_KEY' => '***', 'webhook_secret' => '***', 'name' => 'ok'));
check('strips nested secret keys recursively', audit_strip_secrets(array('acc' => array('token' => 't', 'id' => 1))),
    array('acc' => array('token' => '***', 'id' => 1)));
check('non-array passthrough', audit_strip_secrets('plain'), 'plain');
check('audit_encode(null) is null', audit_encode(null), null);
$json = audit_encode(array('access_token' => 'secret-value', 'ok' => 1));
is_true('audit_encode never contains the raw secret value', strpos($json, 'secret-value') === false);
is_true('audit_encode keeps non-secret values', strpos($json, '"ok":1') !== false);

// ============================================================================================
echo "\n== audit_record() never throws ==\n";

// A CI instance whose db explodes on insert() — audit_record() must swallow it and return 0.
class ThrowingDb
{
    public function table_exists($t) { return true; }
    public function insert($t, $d) { throw new RuntimeException('db is down'); }
    public function insert_id() { return 0; }
}
class FakeCiThrowing { public $db; public $session; public $input;
    function __construct() { $this->db = new ThrowingDb(); $this->session = new FakeSession(); $this->input = new FakeInput(); }
}
class FakeSession { function userdata($k) { return $k === 'user_id' ? 99 : null; } }
class FakeInput { function ip_address() { return '203.0.113.9'; } }

$GLOBALS['__CI'] = new FakeCiThrowing();
function get_instance() { return $GLOBALS['__CI']; }
function log_message($level, $msg) { /* no-op */ }

$result = null; $threw = false;
try { $result = audit_record(1, 'x.y', 'e', '1'); } catch (Throwable $e) { $threw = true; }
is_true('audit_record swallows a throwing db and returns 0 (never throws)', $threw === false && $result === 0);

// A missing table also returns 0, no throw.
class MissingTableDb { function table_exists($t) { return false; } }
class FakeCiMissing { public $db; function __construct() { $this->db = new MissingTableDb(); } }
$GLOBALS['__CI'] = new FakeCiMissing();
check('audit_record returns 0 when audit_log table does not exist yet', audit_record(1, 'x.y'), 0);

// Bad input (no user_id / no action) never even reaches the db.
check('audit_record returns 0 for user_id <= 0', audit_record(0, 'x.y'), 0);
check('audit_record returns 0 for empty action', audit_record(1, ''), 0);

// ============================================================================================
echo "\n== audit_record() writes the row, strips secrets, ip + actor default from CI ==\n";

class RecordingDb {
    public $table; public $row; public $exists = true;
    function table_exists($t) { $this->table = $t; return $this->exists; }
    function insert($t, $d) { $this->table = $t; $this->row = $d; }
    function insert_id() { return 555; }
}
$db = new RecordingDb();
class FakeCiRecording { public $db; public $session; public $input;
    function __construct($db) { $this->db = $db; $this->session = new FakeSession(); $this->input = new FakeInput(); }
}
$GLOBALS['__CI'] = new FakeCiRecording($db);

$id = audit_record(7, 'channel.connected', 'whatsapp_account', '42', null, array('access_token' => 'shhh', 'phone' => '+1'));
check('audit_record returns the inserted id', $id, 555);
check('inserted into audit_log', $db->table, 'audit_log');
check('user_id from the argument', $db->row['user_id'], 7);
check('actor_id defaults from session user_id when not passed', $db->row['actor_id'], 99);
check('actor_type defaults to system', $db->row['actor_type'], 'system');
check('action stored', $db->row['action'], 'channel.connected');
check('entity_type/entity_id stored', array($db->row['entity_type'], $db->row['entity_id']), array('whatsapp_account', '42'));
check('before_json is null when $before is null', $db->row['before_json'], null);
is_true('after_json has the secret stripped', strpos($db->row['after_json'], 'shhh') === false && strpos($db->row['after_json'], '"phone":"+1"') !== false);
check('ip pulled from $CI->input->ip_address()', $db->row['ip'], '203.0.113.9');
is_true('actor_type falls back to system for an unknown value', (function () {
    $db2 = new RecordingDb(); $GLOBALS['__CI'] = new FakeCiRecording($db2);
    audit_record(1, 'a', '', '', null, null, 3, 'bogus');
    return $db2->row['actor_type'] === 'system';
})());

// ============================================================================================
echo "\n== audit_log_list() tenant scoping ==\n";

// Same fake query-builder pattern as tests/inbox/run.php: records the SQL shape.
class FakeQB {
    public $calls = array();
    function from($t) { $this->calls[] = array('from', $t); return $this; }
    function where($a, $b = null) { $this->calls[] = array('where', $a, $b); return $this; }
    function order_by($a, $b) { $this->calls[] = array('order_by', $a, $b); return $this; }
    function limit($a, $b) { $this->calls[] = array('limit', $a, $b); return $this; }
    function get() { return $this; }
    function result_array() { return array(); }
}

$q = new FakeQB();
audit_log_list($q, 4321, array());
check('tenant view: user_id guard is present', in_array(array('where', 'user_id', 4321), $q->calls, true), true);

$q = new FakeQB();
audit_log_list($q, 4321, array('entity_type' => 'automation_rule', 'action' => 'automation.published'));
check('tenant view with filters: user_id guard still present', in_array(array('where', 'user_id', 4321), $q->calls, true), true);
$has_other_tenant_bypass = false;
foreach ($q->calls as $c) { if ($c[0] === 'where' && $c[1] === 'user_id' && $c[2] !== 4321) $has_other_tenant_bypass = true; }
is_true('tenant view: no where(user_id, <other tenant>) ever added', !$has_other_tenant_bypass);

$q = new FakeQB();
audit_log_list($q, null, array());
$has_user_id_where = false;
foreach ($q->calls as $c) { if ($c[0] === 'where' && $c[1] === 'user_id') $has_user_id_where = true; }
is_true('admin view (user_id=null): no user_id filter at all — sees every tenant', !$has_user_id_where);

// The critical cross-tenant regression this guards: tenant A must never be able to pass a
// filter that widens the scope to tenant B or to "all tenants" — user_id is not a filter key.
$q = new FakeQB();
audit_log_list($q, 111, array('user_id' => 222, 'entity_type' => 'x'));
check('tenant view ignores a "user_id" filter key entirely — only the real $user_id arg is used',
    in_array(array('where', 'user_id', 111), $q->calls, true), true);

// ============================================================================================
echo "\n== static wiring: every listed call site invokes audit_record( ==\n";

$root = dirname(__DIR__, 2);
$sites = array(
    'application/controllers/Whatsapp_bot.php'    => 2,   // connect + disconnect
    'application/controllers/Openwa_bot.php'      => 2,   // save (connect/update) + delete
    'application/controllers/Telegram_bot.php'    => 2,
    'application/controllers/Webchat.php'         => 2,
    'application/controllers/Social_accounts.php' => 2,   // connect + disconnect
    'application/controllers/Ai_agents.php'       => 2,   // save_profile + save_assignments
    'application/controllers/Automations.php'     => 2,   // save (publish path) + set_status
    'application/controllers/Sequences.php'       => 2,
    'application/controllers/Crm.php'             => 1,   // deal_move
    'application/controllers/Inbox.php'           => 2,   // assign + takeover
    'application/controllers/Agency_provision.php'=> 1,
    'application/controllers/Messenger_bot.php'   => 1,   // export
);
foreach ($sites as $rel => $min_count) {
    $path = $root . '/' . $rel;
    $src = @file_get_contents($path);
    if ($src === false) { bad($rel, 'file not found'); continue; }
    $n = substr_count($src, 'audit_record(');
    if ($n >= $min_count) ok("$rel calls audit_record( at least $min_count time(s) (found $n)");
    else bad("$rel calls audit_record( at least $min_count time(s)", "found $n");
}

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL === 0 ? 0 : 1);
