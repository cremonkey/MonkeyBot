<?php
/**
 * CRM connector interface + unified outbox tests (Task 25, C10, brief §28). No CodeIgniter
 * bootstrap, no real database, no network.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/crm_connectors/run.php
 *
 * Covers:
 *   - ExternalCrmConnector / SheetCrmConnector delegate to the existing helper functions
 *     (xcrm_store_lead, xcrm_get_config, xcrm_token, crm_sheet_enqueue_lead,
 *     crm_sheet_get_config, crm_sheet_test) with the args unchanged — proven with spies,
 *     never the real network/DB code.
 *   - ConnectorRegistry resolves by key and rejects an unknown one.
 *   - crm_outbox_rows() / crm_outbox_counts() are tenant-scoped and merge both providers.
 *   - crm_outbox_retry() is ownership-checked and respects in-flight/sent rows.
 *   - Crm::outbox_retry() source is ajax_check + csrf_token_check guarded (same source-level
 *     style tests/crm/run.php uses for stage_* endpoints).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
date_default_timezone_set('UTC');

$PASS = 0; $FAIL = 0;
function ok($name) { global $PASS; $PASS++; echo "PASS  $name\n"; }
function bad($name, $why) { global $FAIL; $FAIL++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}
function is_true($name, $v) { check($name, true, $v); }

/* ---------------------------------------------------------------------------------------
 | Spies replacing the real helper functions. function_exists() guards in the real helper
 | files mean these win as long as we never require those files (we require only the
 | connector library classes below).
 * -------------------------------------------------------------------------------------*/
$GLOBALS['__calls'] = array();
function __record($name, $args) { $GLOBALS['__calls'][] = array($name, $args); }
function __calls_for($name) { return array_values(array_filter($GLOBALS['__calls'], function ($c) use ($name) { return $c[0] === $name; })); }

$GLOBALS['__xcrm_store_lead_return'] = true;
function xcrm_store_lead($user_id, $lead) { __record('xcrm_store_lead', array($user_id, $lead)); return $GLOBALS['__xcrm_store_lead_return']; }

$GLOBALS['__xcrm_get_config_return'] = array('user_id' => 1, 'last_error' => 'boom');
function xcrm_get_config($user_id) { __record('xcrm_get_config', array($user_id)); return $GLOBALS['__xcrm_get_config_return']; }

$GLOBALS['__xcrm_token_return'] = 'Bearer abc';
function xcrm_token($config, $force = false) { __record('xcrm_token', array($config, $force)); return $GLOBALS['__xcrm_token_return']; }

$GLOBALS['__sheet_enqueue_return'] = true;
function crm_sheet_enqueue_lead($user_id, $lead) { __record('crm_sheet_enqueue_lead', array($user_id, $lead)); return $GLOBALS['__sheet_enqueue_return']; }

$GLOBALS['__sheet_get_config_return'] = array('id' => 1);
function crm_sheet_get_config($user_id) { __record('crm_sheet_get_config', array($user_id)); return $GLOBALS['__sheet_get_config_return']; }

$GLOBALS['__sheet_test_return'] = array(true, 'Connected.');
function crm_sheet_test(&$config) { __record('crm_sheet_test', array($config)); return $GLOBALS['__sheet_test_return']; }

require __DIR__ . '/../../application/libraries/Crm_connectors/CrmConnectorInterface.php';
require __DIR__ . '/../../application/libraries/Crm_connectors/ExternalCrmConnector.php';
require __DIR__ . '/../../application/libraries/Crm_connectors/SheetCrmConnector.php';
require __DIR__ . '/../../application/libraries/Crm_connectors/ConnectorRegistry.php';

use MonkeyBot\CrmConnectors\ConnectorRegistry;
use MonkeyBot\CrmConnectors\ExternalCrmConnector;
use MonkeyBot\CrmConnectors\SheetCrmConnector;

// ============================================================================================
echo "== ExternalCrmConnector delegates without changing behaviour ==\n";

$xc = new ExternalCrmConnector();
check('key()', $xc->key(), '8xcrm');

$GLOBALS['__calls'] = array();
$lead = array('phone' => '0100', 'deal_id' => 9);
$res = $xc->pushLead(42, $lead);
$calls = __calls_for('xcrm_store_lead');
check('pushLead calls xcrm_store_lead once', count($calls), 1);
check('pushLead passes user_id unchanged', $calls[0][1][0], 42);
check('pushLead passes lead unchanged', $calls[0][1][1], $lead);
check('pushLead ok on true', $res['ok'], true);

$GLOBALS['__calls'] = array();
$GLOBALS['__xcrm_store_lead_return'] = false;
$res = $xc->pushDealUpdate(42, $lead);
check('pushDealUpdate calls xcrm_store_lead too (same dedupe path)', count(__calls_for('xcrm_store_lead')), 1);
check('pushDealUpdate ok=false surfaces the real "not queued" result', $res['ok'], false);
$GLOBALS['__xcrm_store_lead_return'] = true;

$GLOBALS['__calls'] = array();
$res = $xc->testConnection(7);
check('testConnection reads config for the right user', __calls_for('xcrm_get_config')[0][1][0], 7);
check('testConnection forces a fresh token', __calls_for('xcrm_token')[0][1][1], true);
check('testConnection ok on a token', $res['ok'], true);

$GLOBALS['__xcrm_get_config_return'] = null;
$res = $xc->testConnection(7);
check('testConnection: no config -> not ok, no crash', $res['ok'], false);
$GLOBALS['__xcrm_get_config_return'] = array('user_id' => 1, 'last_error' => 'boom');

$GLOBALS['__xcrm_token_return'] = null;
$res = $xc->testConnection(7);
check('testConnection: token failure -> surfaces last_error', $res, array('ok' => false, 'message' => 'boom'));
$GLOBALS['__xcrm_token_return'] = 'Bearer abc';

// ============================================================================================
echo "\n== SheetCrmConnector delegates without changing behaviour ==\n";

$sc = new SheetCrmConnector();
check('key()', $sc->key(), 'gsheet');

$GLOBALS['__calls'] = array();
$lead2 = array('email' => 'a@b.com', 'deal_id' => 3);
$res = $sc->pushLead(5, $lead2);
check('pushLead calls crm_sheet_enqueue_lead once', count(__calls_for('crm_sheet_enqueue_lead')), 1);
check('pushLead passes args unchanged', __calls_for('crm_sheet_enqueue_lead')[0][1], array(5, $lead2));
check('pushLead ok', $res['ok'], true);

$GLOBALS['__calls'] = array();
$sc->pushDealUpdate(5, $lead2);
check('pushDealUpdate also calls crm_sheet_enqueue_lead', count(__calls_for('crm_sheet_enqueue_lead')), 1);

$res = $sc->testConnection(5);
check('testConnection ok mirrors crm_sheet_test()', $res, array('ok' => true, 'message' => 'Connected.'));

$GLOBALS['__sheet_get_config_return'] = null;
$res = $sc->testConnection(5);
check('testConnection: no config -> not ok', $res['ok'], false);
$GLOBALS['__sheet_get_config_return'] = array('id' => 1);

// ============================================================================================
echo "\n== ConnectorRegistry resolves by key ==\n";

check('keys()', ConnectorRegistry::keys(), array('8xcrm', 'gsheet'));
is_true('has(8xcrm)', ConnectorRegistry::has('8xcrm'));
is_true('has(gsheet)', ConnectorRegistry::has('gsheet'));
check('has(unknown) is false', ConnectorRegistry::has('nope'), false);
check('for(8xcrm) instance', get_class(ConnectorRegistry::for('8xcrm')), ExternalCrmConnector::class);
check('for(gsheet) instance', get_class(ConnectorRegistry::for('gsheet')), SheetCrmConnector::class);

try {
    ConnectorRegistry::for('nope');
    bad('for(unknown) throws', 'no exception raised');
} catch (InvalidArgumentException $e) {
    ok('for(unknown) throws InvalidArgumentException');
}

$all = ConnectorRegistry::all();
check('all() returns both, keyed', array_keys($all), array('8xcrm', 'gsheet'));

/* =============================================================================================
 | Outbox helper: a small fake DB scoped to crm_external_log only.
 * ===========================================================================================*/
class OutboxFakeResult
{
    private $rows;
    function __construct($rows) { $this->rows = array_values($rows); }
    function row_array() { return $this->rows[0] ?? array(); }
    function result_array() { return $this->rows; }
}
class OutboxFakeDB
{
    public $rows = array();
    private $q;
    function __construct() { $this->reset(); }
    private function reset() { $this->q = array('where' => array(), 'order' => null, 'limit' => null, 'select' => null); return $this; }
    function from($t) { $sel = $this->q['select'] ?? null; $this->reset(); $this->q['select'] = $sel; return $this; }
    function select($c) { $this->q['select'] = $c; return $this; }
    function where($a, $b = null) { $this->q['where'][] = array($a, $b); return $this; }
    function group_by($c) { $this->q['group'] = $c; return $this; }
    function order_by($c, $d = 'ASC') { $this->q['order'] = array($c, $d); return $this; }
    function limit($n) { $this->q['limit'] = $n; return $this; }
    private function matched() {
        $rows = $this->rows;
        foreach ($this->q['where'] as $w) {
            list($f, $v) = $w;
            $rows = array_values(array_filter($rows, function ($r) use ($f, $v) { return ($r[$f] ?? null) == $v; }));
        }
        return $rows;
    }
    function get() {
        $rows = $this->matched();
        if (isset($this->q['select']) && strpos($this->q['select'], 'COUNT(*)') !== false) {
            $groups = array();
            foreach ($rows as $r) { $groups[$r['status']] = ($groups[$r['status']] ?? 0) + 1; }
            $out = array();
            foreach ($groups as $status => $c) $out[] = array('status' => $status, 'c' => $c);
            $this->reset();
            return new OutboxFakeResult($out);
        }
        if ($this->q['order']) {
            list($c, $d) = $this->q['order'];
            usort($rows, function ($a, $b) use ($c, $d) { $cmp = $a[$c] <=> $b[$c]; return $d === 'DESC' ? -$cmp : $cmp; });
        }
        if ($this->q['limit'] !== null) $rows = array_slice($rows, 0, $this->q['limit']);
        $this->reset();
        return new OutboxFakeResult($rows);
    }
    function count_all_results() { $n = count($this->matched()); $this->reset(); return $n; }
    function update($t, $data) {
        $ids = array_column($this->matched(), 'id');
        foreach ($this->rows as $i => $r) {
            if (in_array($r['id'], $ids, true)) $this->rows[$i] = array_merge($r, $data);
        }
        $this->reset();
        return true;
    }
    function table_exists($t) { return $t === 'crm_external_log'; }
}
class OutboxFakeCI { public $db; function __construct($db) { $this->db = $db; } }

$GLOBALS['__OUTBOX_CI'] = null;
// crm_outbox_helper.php calls the same global get_instance() already defined for the
// connector spies above (none is defined yet at file scope for that name in this runner,
// so define it once, shared by both halves of this suite).
function &get_instance() { return $GLOBALS['__OUTBOX_CI']; }
function log_message($level, $msg) { /* no-op */ }

require __DIR__ . '/../../application/helpers/crm_outbox_helper.php';

function seed_outbox() {
    return array(
        array('id' => 1, 'user_id' => 1, 'deal_id' => 10, 'provider' => '8xcrm', 'status' => 'pending',  'attempts' => 0, 'phone' => '0100', 'error' => null,        'http_code' => null, 'remote_id' => null, 'created_at' => '2026-09-01 10:00:00', 'next_attempt_at' => null),
        array('id' => 2, 'user_id' => 1, 'deal_id' => 11, 'provider' => 'gsheet', 'status' => 'sent',     'attempts' => 1, 'phone' => '0101', 'error' => null,        'http_code' => 200,  'remote_id' => null, 'created_at' => '2026-09-01 11:00:00', 'next_attempt_at' => null),
        array('id' => 3, 'user_id' => 1, 'deal_id' => 12, 'provider' => '8xcrm', 'status' => 'failed',   'attempts' => 5, 'phone' => '0102', 'error' => 'HTTP 422', 'http_code' => 422,  'remote_id' => null, 'created_at' => '2026-09-01 09:00:00', 'next_attempt_at' => null),
        array('id' => 4, 'user_id' => 2, 'deal_id' => 20, 'provider' => 'gsheet', 'status' => 'pending', 'attempts' => 0, 'phone' => '0200', 'error' => null,        'http_code' => null, 'remote_id' => null, 'created_at' => '2026-09-01 12:00:00', 'next_attempt_at' => null),
        array('id' => 5, 'user_id' => 1, 'deal_id' => 13, 'provider' => '8xcrm', 'status' => 'processing', 'attempts' => 1, 'phone' => '0103', 'error' => 'claim', 'http_code' => null, 'remote_id' => null, 'created_at' => '2026-09-01 13:00:00', 'next_attempt_at' => '2026-09-01 13:00:00'),
    );
}

// ============================================================================================
echo "\n== crm_outbox_rows() / crm_outbox_counts() are tenant-scoped and merge both providers ==\n";

$db = new OutboxFakeDB();
$db->rows = seed_outbox();
$GLOBALS['__OUTBOX_CI'] = new OutboxFakeCI($db);

$rows = crm_outbox_rows(1);
check('tenant 1 sees only its own 4 rows', count($rows), 4);
$ids = array_column($rows, 'id'); sort($ids);
check('tenant 1 never sees tenant 2 row (id 4)', $ids, array(1, 2, 3, 5));

$rows2 = crm_outbox_rows(2);
check('tenant 2 sees only its own row', array_column($rows2, 'id'), array(4));

$byProvider = array();
foreach ($rows as $r) $byProvider[$r['id']] = $r['provider'];
ksort($byProvider);
check('providers preserved (both sources present)', $byProvider, array(1 => '8xcrm', 2 => 'gsheet', 3 => '8xcrm', 5 => '8xcrm'));

$filtered = crm_outbox_rows(1, array('provider' => 'gsheet'));
check('provider filter scopes further', array_column($filtered, 'id'), array(2));

$counts = crm_outbox_counts(1);
check('counts are tenant-scoped', $counts, array('pending' => 1, 'processing' => 1, 'sent' => 1, 'failed' => 1));

// ============================================================================================
echo "\n== crm_outbox_retry() is ownership-checked ==\n";

$db = new OutboxFakeDB();
$db->rows = seed_outbox();
$GLOBALS['__OUTBOX_CI'] = new OutboxFakeCI($db);

$res = crm_outbox_retry(2, 1); // tenant 2 tries to retry tenant 1's row
check('cross-tenant retry refused', $res['ok'], false);
$row1 = array_values(array_filter($db->rows, function ($r) { return $r['id'] === 1; }))[0];
check('cross-tenant retry made no change', $row1['status'], 'pending');

$res = crm_outbox_retry(1, 5); // processing row
check('retry refused while processing', $res['ok'], false);

$res = crm_outbox_retry(1, 2); // sent row
check('retry refused once sent', $res['ok'], false);

$res = crm_outbox_retry(1, 3); // failed row, owned
check('retry accepted for a failed, owned row', $res['ok'], true);
$row3 = array_values(array_filter($db->rows, function ($r) { return $r['id'] === 3; }))[0];
check('retry resets status to pending', $row3['status'], 'pending');
check('retry clears attempts on a failed row (full backoff again)', $row3['attempts'], 0);
check('retry clears the stored error', $row3['error'], null);

$res = crm_outbox_retry(1, 1); // pending row, owned
check('retry accepted for a pending, owned row', $res['ok'], true);
$row1b = array_values(array_filter($db->rows, function ($r) { return $r['id'] === 1; }))[0];
check('retry on a pending row leaves attempts untouched', $row1b['attempts'], 0);

$res = crm_outbox_retry(1, 999);
check('retry on unknown id refused', $res['ok'], false);

// ============================================================================================
echo "\n== Crm::outbox_retry() is ajax + CSRF + ownership guarded (source-level) ==\n";

$src = file_get_contents(__DIR__ . '/../../application/controllers/Crm.php');
if (preg_match('/function\s+outbox_retry\s*\(\s*\)\s*\{(.*?)\n    \}/s', $src, $m)) {
    $body = $m[1];
    is_true('outbox_retry calls ajax_check()', strpos($body, 'ajax_check()') !== false);
    is_true('outbox_retry calls csrf_token_check()', strpos($body, 'csrf_token_check()') !== false);
    is_true('outbox_retry delegates to crm_outbox_retry() with $this->uid (ownership)', strpos($body, 'crm_outbox_retry($this->uid') !== false);
} else {
    bad('outbox_retry() found in Crm.php', 'method body not matched');
}

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL ? 1 : 0);
