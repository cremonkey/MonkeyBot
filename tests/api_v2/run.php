<?php
/**
 * API v2 (read-only) unit tests — Task 28 (C13, brief §37). No DB, no CodeIgniter bootstrap, no
 * network — exercises application/helpers/api_helper.php (token auth, pagination, envelope,
 * rate limit) plus a static cross-tenant/scoping audit of the api/v2 controllers, same style as
 * tests/cross_tenant/run.php and tests/customers/run.php.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/api_v2/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('api_helper.php must not need a CI instance for these functions'); }
function log_message($level, $msg) { /* no-op */ }

require __DIR__ . '/../../application/helpers/api_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ------------------------------------------------------------------------------------------
// Fake CodeIgniter query builder, same shape/spirit as tests/customers/run.php's FakeQB, plus
// insert/update/set so api_rate_limit_check() and api_token_lookup() can run against it.
class FakeQB
{
    public $calls = array();
    public $table_exists_map = array();
    public $rows_by_table = array();
    public $where_conditions = array();
    public $updates = array();
    public $inserts = array();
    private $set_fields = array();

    public function __construct(array $tables_ready = array()) { $this->table_exists_map = $tables_ready; }

    public function select($s) { $this->calls[] = array('select', $s); return $this; }
    public function where($a, $b = null, $escape = null) { $this->calls[] = array('where', $a, $b); $this->where_conditions[$a] = $b; return $this; }
    public function limit($a, $b = null) { $this->calls[] = array('limit', $a, $b); return $this; }
    public function set($field, $value, $escape = true) { $this->set_fields[$field] = $value; return $this; }
    public function table_exists($t) { return !empty($this->table_exists_map[$t]); }

    public function get($table = null) {
        $rows = isset($this->rows_by_table[$table ?? 'api_tokens']) ? $this->rows_by_table[$table ?? 'api_tokens'] : array();
        // filter by whatever where() conditions were recorded this call
        $filtered = array_values(array_filter($rows, function ($row) {
            foreach ($this->where_conditions as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] != $v) return false;
            }
            return true;
        }));
        $this->where_conditions = array();
        return new FakeResult($filtered);
    }

    public function insert($table, array $data) { $this->inserts[] = array($table, $data); return true; }
    public function update($table) { $this->updates[] = array($table, $this->set_fields, $this->where_conditions); $this->set_fields = array(); $this->where_conditions = array(); return true; }
}
class FakeResult
{
    private $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function row_array() { return $this->rows ? $this->rows[0] : array(); }
    public function result_array() { return $this->rows; }
}

// ============================================================================================
echo "== api_token_hash() / api_token_generate(): never the plaintext ==\n";
$gen = api_token_generate();
check('hash is sha256 of the plaintext', $gen['hash'], hash('sha256', $gen['plaintext']));
check('plaintext has the mb_ prefix', strpos($gen['plaintext'], 'mb_') === 0, true);
check('hash never equals the plaintext', $gen['hash'] === $gen['plaintext'], false);

echo "== api_bearer_token_from_header() ==\n";
check('valid Bearer header', api_bearer_token_from_header('Bearer abc123'), 'abc123');
check('missing header', api_bearer_token_from_header(''), '');
check('wrong scheme', api_bearer_token_from_header('Basic abc123'), '');
check('no scheme at all', api_bearer_token_from_header('abc123'), '');

echo "== api_token_state(): ok / revoked / expired ==\n";
$now = strtotime('2026-09-28 12:00:00');
check('no revoked_at, no expires_at -> ok', api_token_state(array('revoked_at' => null, 'expires_at' => null), $now), 'ok');
check('revoked_at set -> revoked (even if not yet expired)', api_token_state(array('revoked_at' => '2026-09-01 00:00:00', 'expires_at' => null), $now), 'revoked');
check('expires_at in the past -> expired', api_token_state(array('revoked_at' => null, 'expires_at' => '2026-09-01 00:00:00'), $now), 'expired');
check('expires_at in the future -> ok', api_token_state(array('revoked_at' => null, 'expires_at' => '2026-12-01 00:00:00'), $now), 'ok');
check('expires_at exactly now -> expired (boundary is inclusive)', api_token_state(array('revoked_at' => null, 'expires_at' => date('Y-m-d H:i:s', $now)), $now), 'expired');

echo "== api_token_authenticate(): missing / not_found / revoked / expired / valid, tenant carried on the row ==\n";
$db = new FakeQB(array('api_tokens' => true));
check('missing Authorization header', api_token_authenticate($db, ''), array('ok' => false, 'reason' => 'missing'));

$hash_valid = api_token_hash('mb_valid');
$hash_revoked = api_token_hash('mb_revoked');
$hash_expired = api_token_hash('mb_expired');
$db->rows_by_table['api_tokens'] = array(
    array('id' => 1, 'user_id' => 555, 'token_hash' => $hash_valid, 'scopes' => '*', 'revoked_at' => null, 'expires_at' => null),
    array('id' => 2, 'user_id' => 777, 'token_hash' => $hash_revoked, 'scopes' => '*', 'revoked_at' => '2026-09-01 00:00:00', 'expires_at' => null),
    array('id' => 3, 'user_id' => 888, 'token_hash' => $hash_expired, 'scopes' => '*', 'revoked_at' => null, 'expires_at' => '2020-01-01 00:00:00'),
);

$r = api_token_authenticate($db, 'Bearer mb_valid', $now);
check('valid token -> ok, correct tenant carried on the row', array($r['ok'], (int) $r['token']['user_id']), array(true, 555));

$r = api_token_authenticate($db, 'Bearer mb_revoked', $now);
check('revoked token -> rejected', $r, array('ok' => false, 'reason' => 'revoked'));

$r = api_token_authenticate($db, 'Bearer mb_expired', $now);
check('expired token -> rejected', $r, array('ok' => false, 'reason' => 'expired'));

$r = api_token_authenticate($db, 'Bearer mb_not_a_real_token', $now);
check('unknown token -> rejected, never leaks which tenant/reason beyond not_found', $r, array('ok' => false, 'reason' => 'not_found'));

echo "== cross-tenant: a valid token never resolves to another tenant's user_id ==\n";
$db->rows_by_table['api_tokens'][] = array('id' => 4, 'user_id' => 999, 'token_hash' => api_token_hash('mb_other_tenant'), 'scopes' => '*', 'revoked_at' => null, 'expires_at' => null);
$a = api_token_authenticate($db, 'Bearer mb_valid', $now);
$b = api_token_authenticate($db, 'Bearer mb_other_tenant', $now);
check('two different tokens resolve to two different tenants, never mixed', array((int) $a['token']['user_id'], (int) $b['token']['user_id']), array(555, 999));

echo "== api_token_authenticate() before the migration has run (table missing) ==\n";
$db_no_table = new FakeQB(array('api_tokens' => false));
check('no api_tokens table yet -> not_found, not a crash', api_token_authenticate($db_no_table, 'Bearer mb_valid'), array('ok' => false, 'reason' => 'not_found'));

// ============================================================================================
echo "== api_pagination_bounds() ==\n";
check('defaults on invalid input', api_pagination_bounds(0, 0), array(1, 20, 0));
check('page 3, per_page 10 -> offset 20', api_pagination_bounds(3, 10), array(3, 10, 20));
check('per_page over max clamps to default', api_pagination_bounds(1, 5000), array(1, 20, 0));
check('negative page clamps to 1', api_pagination_bounds(-5, 10), array(1, 10, 0));

echo "== api_pagination_meta() ==\n";
check('total_pages rounds up', api_pagination_meta(2, 10, 25), array('page' => 2, 'per_page' => 10, 'total' => 25, 'total_pages' => 3));
check('zero rows -> 0 pages', api_pagination_meta(1, 10, 0), array('page' => 1, 'per_page' => 10, 'total' => 0, 'total_pages' => 0));

// ============================================================================================
echo "== api_envelope() / api_error_envelope(): consistent {data,meta} / {error,meta} shape ==\n";
check('envelope shape', api_envelope(array('a' => 1), array('page' => 1)), array('data' => array('a' => 1), 'meta' => array('page' => 1)));
check('empty meta still present as an array', array_key_exists('meta', api_envelope(array())), true);
check('error envelope shape', api_error_envelope('nope'), array('error' => array('message' => 'nope'), 'meta' => array()));

// ============================================================================================
echo "== api_scope_allows() ==\n";
check("'*' allows everything", api_scope_allows('*', 'conversations'), true);
check('empty scopes allows everything (back-compat default)', api_scope_allows('', 'crm'), true);
check('exact match in a list', api_scope_allows('conversations,crm', 'crm'), true);
check('not in the list -> denied', api_scope_allows('conversations,crm', 'analytics'), false);
check('case-insensitive', api_scope_allows('Conversations', 'conversations'), true);

// ============================================================================================
echo "== api_rate_limit_check(): fixed one-minute window, table-based, fails open pre-migration ==\n";
$rdb = new FakeQB(array('api_rate_limit' => true));
$now2 = strtotime('2026-09-28 12:00:30');
check('first request in the window -> allowed, row inserted', api_rate_limit_check($rdb, 42, 3, $now2), true);
check('insert recorded the right window + starting count', $rdb->inserts[0], array('api_rate_limit', array('token_id' => 42, 'window_start' => '2026-09-28 12:00:00', 'request_count' => 1)));

// second/third requests read the row the "insert" produced — feed it back into rows_by_table
$rdb->rows_by_table['api_rate_limit'] = array(array('token_id' => 42, 'window_start' => '2026-09-28 12:00:00', 'request_count' => 1));
check('under the limit -> allowed, existing row incremented', api_rate_limit_check($rdb, 42, 3, $now2), true);
check('increment recorded against the right token+window', $rdb->updates[0][2], array('token_id' => 42, 'window_start' => '2026-09-28 12:00:00'));

$rdb->rows_by_table['api_rate_limit'] = array(array('token_id' => 42, 'window_start' => '2026-09-28 12:00:00', 'request_count' => 3));
check('at the limit -> denied', api_rate_limit_check($rdb, 42, 3, $now2), false);

$rdb_no_table = new FakeQB(array('api_rate_limit' => false));
check('no api_rate_limit table yet -> fails open (allowed)', api_rate_limit_check($rdb_no_table, 42, 3, $now2), true);

$rdb2 = new FakeQB(array('api_rate_limit' => true));
$rdb2->rows_by_table['api_rate_limit'] = array(array('token_id' => 42, 'window_start' => '2026-09-28 12:00:00', 'request_count' => 3));
check('a different token is never blocked by another token\'s count', api_rate_limit_check($rdb2, 99, 3, $now2), true);

// ============================================================================================
echo "== static audit: every api/v2 controller derives tenant scope from the token, never a session ==\n";
$ctrl_dir = __DIR__ . '/../../application/controllers/api/v2';
$controller_files = glob($ctrl_dir . '/*.php');
check('api/v2 controller files exist', count($controller_files) >= 4, true);

foreach ($controller_files as $file) {
    $src = file_get_contents($file);
    $name = basename($file);
    check("$name: extends Api_v2_controller", (bool) preg_match('/class\s+\w+\s+extends\s+Api_v2_controller/', $src), true);
    check("$name: never reads session userdata for tenant scope", strpos($src, "session->userdata") === false, true);
    check("$name: uses \$this->uid (the token's tenant) for its scoped queries", strpos($src, '$this->uid') !== false, true);
}

$base_src = file_get_contents(__DIR__ . '/../../application/libraries/Api_v2_controller.php');
check('base controller: uid is set from the authenticated token row, not request input', strpos($base_src, "\$this->uid = (int) \$result['token']['user_id']") !== false, true);
check('base controller: unauthenticated requests are rejected before any endpoint logic runs (constructor order)',
    strpos($base_src, '$this->authenticate();') < strpos($base_src, '$this->enforce_rate_limit();'), true);

// ============================================================================================
echo "== migration file: idempotent, additive, no destructive statements, no semicolons in comments ==\n";
$mig = file_get_contents(__DIR__ . '/../../assets/backup_db/migrations/2026-10-04g-api-tokens.sql');
$mig_code_only = implode("\n", array_filter(explode("\n", $mig), function ($l) { return strpos(ltrim($l), '--') !== 0; }));
check('has DO 0; before the first real CREATE TABLE statement (idempotent no-op statement first)',
    strpos($mig_code_only, 'DO 0;') !== false && strpos($mig_code_only, 'DO 0;') < strpos($mig_code_only, 'CREATE TABLE'), true);
check('uses CREATE TABLE IF NOT EXISTS (idempotent)', substr_count($mig, 'CREATE TABLE IF NOT EXISTS') >= 2, true);
check('no DROP statements', stripos($mig, 'DROP ') === false, true);
check('no DELETE statements', stripos($mig, 'DELETE ') === false, true);
$comment_lines_with_semicolon = 0;
foreach (explode("\n", $mig) as $line) {
    $t = ltrim($line);
    if (strpos($t, '--') === 0 && strpos($t, ';') !== false) $comment_lines_with_semicolon++;
}
check('no semicolons inside comment lines (migration runner splits on ; at end of line)', $comment_lines_with_semicolon, 0);

// ============================================================================================
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
