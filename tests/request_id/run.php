<?php
/**
 * Request/correlation id unit tests (remaining-work Task 13, brief 39/51). No DB, no
 * CodeIgniter bootstrap, no network — exercises the real functions used at webhook entry,
 * on internal forwards, and by the writers, plus a wiring check on the System Health
 * "Failures (24h)" panel builder.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/request_id/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
define('APPPATH', __DIR__ . '/../../application/');

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

function log_message($level, $msg) { /* no-op */ }

// ------------------------------------------------------------------------------------------
// A fake CI superobject: what request_id_helper.php, webhook_security_helper.php and the
// writers actually touch — $CI->mb_request_id, $CI->config->item(), $CI->db.
class FakeConfig { public $items = array(); public function item($k) { return $this->items[$k] ?? ''; } }

class FakeDB
{
    public $tables = array();   // table => array(col => true)
    public $inserts = array();  // table => array of rows
    public function table_exists($t) { return isset($this->tables[$t]); }
    public function field_exists($c, $t) { return isset($this->tables[$t][$c]); }
    public function insert($t, $row) { $this->inserts[$t][] = $row; return true; }
    public function insert_id() { return 1; }
    // Minimal query-builder chain, used only when table_exists() is true for a pricing lookup.
    public function select($x) { return $this; }
    public function from($x) { return $this; }
    public function where($a, $b = null) { return $this; }
    public function limit($n) { return $this; }
    public function group_by($x) { return $this; }
    public function get() { return $this; }
    public function row_array() { return null; }
    public function result_array() { return array(); }
    public function count_all_results() { return 0; }
}

class FakeCI
{
    public $mb_request_id;
    public $config;
    public $db;
    public function __construct() { $this->config = new FakeConfig(); $this->db = new FakeDB(); }
}

$GLOBALS['__fake_ci'] = new FakeCI();
function &get_instance() { return $GLOBALS['__fake_ci']; }
function reset_ci() { $GLOBALS['__fake_ci'] = new FakeCI(); return $GLOBALS['__fake_ci']; }

require __DIR__ . '/../../application/helpers/request_id_helper.php';
require __DIR__ . '/../../application/helpers/webhook_security_helper.php';
require __DIR__ . '/../../application/helpers/ai_usage_helper.php';
require __DIR__ . '/../../application/helpers/system_health_failures_helper.php';

// ============================================================================================
echo "== 1. request_id_set() once, request_id_get() repeatedly, within one \"request\" ==\n";

reset_ci();
check('no id set yet -> get returns null', request_id_get(), null);
$id = request_id_set();
check('set() returns a 36-char UUIDv4-shaped id', (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id), true);
check('get() #1 returns the same id', request_id_get(), $id);
check('get() #2 (later in the same request) still returns the same id', request_id_get(), $id);
check('get() #3 still returns the same id', request_id_get(), $id);

reset_ci();
$forwarded = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$got = request_id_set($forwarded);
check('set($id) with a given id reuses it verbatim', $got, $forwarded);
check('get() after set($id) returns that same forwarded id', request_id_get(), $forwarded);

// ============================================================================================
echo "\n== 2. mb_internal_headers() carries the request id ==\n";

reset_ci();
$headers = mb_internal_headers('resp-body');
$has_rid_header = false;
foreach ($headers as $h) if (strpos($h, 'X-MB-Request-Id:') === 0) $has_rid_header = true;
check('no request id set -> mb_internal_headers() has no X-MB-Request-Id header', $has_rid_header, false);

reset_ci();
request_id_set('11111111-2222-4333-8444-555555555555');
$headers = mb_internal_headers('resp-body');
$rid_header = null;
foreach ($headers as $h) if (strpos($h, 'X-MB-Request-Id:') === 0) $rid_header = $h;
check('request id set -> mb_internal_headers() includes it', $rid_header, 'X-MB-Request-Id: 11111111-2222-4333-8444-555555555555');
$has_internal_header = false;
foreach ($headers as $h) if (strpos($h, 'X-MB-Internal:') === 0) $has_internal_header = true;
check('the existing HMAC X-MB-Internal header is still present, unchanged', $has_internal_header, true);

// ============================================================================================
echo "\n== 3. mb_internal_gate() accepts a forwarded request id ==\n";

reset_ci();
$GLOBALS['__fake_ci']->config->items['internal_webhook_mode'] = 'off'; // avoids APPPATH cache-file writes; gate logic under test runs before this check
$_SERVER['HTTP_X_MB_REQUEST_ID'] = 'cafebabe-0000-4000-8000-000000000000';
$_SERVER['HTTP_X_MB_INTERNAL'] = '';
$result = mb_internal_gate('resp-body');
check('mb_internal_gate() still returns true in "off" mode', $result, true);
check('mb_internal_gate() called request_id_set() with the forwarded header value', request_id_get(), 'cafebabe-0000-4000-8000-000000000000');
unset($_SERVER['HTTP_X_MB_REQUEST_ID'], $_SERVER['HTTP_X_MB_INTERNAL']);

reset_ci();
$GLOBALS['__fake_ci']->config->items['internal_webhook_mode'] = 'off';
mb_internal_gate('resp-body'); // no X-MB-Request-Id header at all
check('mb_internal_gate() with no forwarded header leaves the request id unset', request_id_get(), null);

// ============================================================================================
echo "\n== 4. writer (ai_usage_record) sets request_id only when the column exists ==\n";
// ai_usage_record() caches field_exists('request_id', ...) in a `static` (mirroring
// Inbox.php's own field_exists caching), which is correct for one real PHP-FPM request but
// means the true/false branches must not share a process here — each runs in its own PHP
// subprocess (still no DB, no network: just this same interpreter, invoked twice).
$case_script = __DIR__ . '/_writer_case.php';
foreach (array('missing', 'present') as $case) {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($case_script) . ' ' . escapeshellarg($case) . ' 2>&1');
    $line = trim((string) $out);
    if ($case === 'missing') {
        check('field_exists(request_id) = false -> INSERT omits request_id entirely', $line, 'OMITTED');
    } else {
        check('field_exists(request_id) = true -> INSERT includes the current request id', $line, 'writer-test-1111-4000-8000-000000000000');
    }
}

// ============================================================================================
echo "\n== 5. System Health \"Failures (24h)\" panel: each sub-query is table_exists()-guarded ==\n";

$db = new FakeDB();
$panels = system_health_failures_24h($db);
check('no source tables at all -> no panels built (never faked)', $panels, array());

$db = new FakeDB();
$db->tables['ai_failure_log'] = array('kind' => true, 'created_at' => true);
$db->tables['messenger_bot_reply_error_log'] = array('error_time' => true);
$db->tables['crm_external_log'] = array('status' => true, 'created_at' => true);
// jobs table deliberately absent: this codebase has none.
$panels = system_health_failures_24h($db);
check('ai_failure_log present -> ai_failures + tool_failures panels built', isset($panels['ai_failures']) && isset($panels['tool_failures']), true);
check('messenger_bot_reply_error_log present (has error_time) -> send_failures panel built', isset($panels['send_failures']), true);
check('crm_external_log present (has status) -> crm_push_failures panel built', isset($panels['crm_push_failures']), true);
check('no jobs table -> job_failures panel skipped, not faked', isset($panels['job_failures']), false);

$db = new FakeDB();
$db->tables['messenger_bot_reply_error_log'] = array(); // table exists but no error_time column
$panels = system_health_failures_24h($db);
check('messenger_bot_reply_error_log without a time column -> send_failures panel skipped', isset($panels['send_failures']), false);

// ============================================================================================
echo "\n== summary ==\n";
echo "pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
