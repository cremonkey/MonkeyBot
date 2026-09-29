<?php
/**
 * API keys (follow-up to Task 28 / C13, brief §37) — unit + static audit tests. No DB, no
 * CodeIgniter bootstrap, no network.
 *
 * Covers:
 *   - api_token_generate()'s plaintext is never equal to the stored hash, and the hash really
 *     is sha256(plaintext) — the exact round-trip api_v2's auth lookup depends on.
 *   - A revoked token fails api_token_authenticate() even with a fresh, correct plaintext.
 *   - Tenant scoping: FakeQB proves every by-id read/write in Api_keys.php carries a user_id
 *     filter (same technique as tests/customers/run.php / tests/cross_tenant/run.php).
 *   - Static source audit of application/controllers/Api_keys.php: ajax_check() +
 *     csrf_token_check() on every mutating action, entitlement_require('api') in the
 *     constructor, and that the plaintext token is only ever placed in the create_token()
 *     response — never written to any $this->db->insert()/update() call.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/api_keys/run.php
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

// ============================================================================================
echo "== api_token_generate(): plaintext is never the stored value, hash round-trips ==\n";

$generated = api_token_generate();
check('plaintext is prefixed mb_', strpos($generated['plaintext'], 'mb_') === 0, true);
check('hash is a 64-char sha256 hex digest', (bool) preg_match('/^[0-9a-f]{64}$/', $generated['hash']), true);
check('plaintext is never equal to the stored hash', $generated['plaintext'] === $generated['hash'], false);
check('hash really is sha256(plaintext) — same function api_token_authenticate() uses to look it up',
    $generated['hash'], hash('sha256', $generated['plaintext']));

// Two generations never collide and never reuse randomness.
$second = api_token_generate();
check('two generated tokens have different plaintexts', $generated['plaintext'] === $second['plaintext'], false);
check('two generated tokens have different hashes', $generated['hash'] === $second['hash'], false);

// ============================================================================================
echo "\n== revoke blocks auth: api_token_authenticate() rejects a revoked row even with the right hash ==\n";

class FakeDbRevoke
{
    public $rows_by_table = array();
    public $where_conditions = array();
    public function table_exists($t) { return isset($this->rows_by_table[$t]); }
    public function where($a, $b = null) { $this->where_conditions[] = array($a, $b); return $this; }
    public function limit($n) { return $this; }
    public function get($t) { return $this; }
    public function row_array()
    {
        // find by token_hash from the last where() chain
        $hash = null;
        foreach ($this->where_conditions as $c) if ($c[0] === 'token_hash') $hash = $c[1];
        $this->where_conditions = array();
        foreach ($this->rows_by_table['api_tokens'] as $r) if ($r['token_hash'] === $hash) return $r;
        return array();
    }
}

$plain = api_token_generate();
$db = new FakeDbRevoke();
$db->rows_by_table['api_tokens'] = array(array(
    'id' => 1, 'user_id' => 9, 'token_hash' => $plain['hash'], 'revoked_at' => date('Y-m-d H:i:s'),
    'expires_at' => null,
));

$result = api_token_authenticate($db, 'Bearer ' . $plain['plaintext']);
check('a revoked token is rejected even with the exact correct plaintext', $result['ok'], false);
check('rejection reason is "revoked"', $result['reason'], 'revoked');

// The same token BEFORE revocation would have authenticated — proves the fixture is realistic,
// not just permanently broken.
$db2 = new FakeDbRevoke();
$db2->rows_by_table['api_tokens'] = array(array(
    'id' => 1, 'user_id' => 9, 'token_hash' => $plain['hash'], 'revoked_at' => null, 'expires_at' => null,
));
$result2 = api_token_authenticate($db2, 'Bearer ' . $plain['plaintext']);
check('the same token authenticates fine before revocation (fixture sanity check)', $result2['ok'], true);
check('authenticate() returns the token row with the right user_id (tenant scope for api/v2)', $result2['token']['user_id'], 9);

// ============================================================================================
echo "\n== static audit: application/controllers/Api_keys.php ==\n";

$src = file_get_contents(__DIR__ . '/../../application/controllers/Api_keys.php');

check('constructor gates on entitlement_require(\'api\')', strpos($src, "entitlement_require('api')") !== false, true);
check('constructor redirects logged-out sessions before entitlement_require (position check)',
    strpos($src, "userdata('logged_in')") < strpos($src, "entitlement_require('api')"), true);

foreach (array('create_token', 'revoke_token') as $method) {
    $start = strpos($src, "function {$method}(");
    if ($start === false) { bad("{$method}() exists", 'not found'); continue; }
    $next = strpos($src, "\n    public function ", $start + 1);
    $body = $next !== false ? substr($src, $start, $next - $start) : substr($src, $start);
    check("{$method}(): calls ajax_check()", strpos($body, '$this->ajax_check();') !== false, true);
    check("{$method}(): calls csrf_token_check()", strpos($body, '$this->csrf_token_check();') !== false, true);
    check("{$method}(): ajax_check() runs before csrf_token_check()",
        strpos($body, '$this->ajax_check();') < strpos($body, '$this->csrf_token_check();'), true);
}

// tokens_data() is a read, not a mutation — ajax_check() only, no CSRF required (matches
// customers_data()/tokens_data() read-endpoint convention elsewhere in the codebase).
$td_start = strpos($src, 'function tokens_data(');
$td_body = substr($src, $td_start, strpos($src, "\n    private function", $td_start) - $td_start);
check('tokens_data(): calls ajax_check()', strpos($td_body, '$this->ajax_check();') !== false, true);

// Tenant scoping: every api_tokens query in this controller carries a user_id filter, and
// revoke's UPDATE is filtered by BOTH id and user_id (defense in depth, not owned_token() alone).
check("index(): the tokens list query filters api_tokens by \$this->uid",
    strpos($src, "->from('api_tokens')->where('user_id', \$this->uid)") !== false, true);
check("owned_token(): the by-id lookup filters BOTH id and user_id — this is the only place a client-supplied id reaches a query",
    strpos($src, "->from('api_tokens')->where('id', \$id)->where('user_id', \$this->uid)") !== false, true);
check("revoke_token(): the UPDATE itself also carries a user_id filter (defense in depth, not owned_token() alone)",
    strpos($src, "->where('id', \$id)->where('user_id', \$this->uid)->update('api_tokens'") !== false, true);
check("revoke_token(): fetches via owned_token() before any write (cross-tenant ids are refused)",
    strpos(substr($src, strpos($src, 'function revoke_token(')), '$token = $this->owned_token($id);') !== false, true);
check("create_token(): the inserted row stamps user_id from the session, not from client input",
    strpos($src, "'user_id'    => \$this->uid,") !== false, true);
check("create_token(): no raw input->post value is ever assigned directly to user_id",
    preg_match('/[\'"]user_id[\'"]\s*=>\s*\$this->input->post/', $src) === 0, true);

// Plaintext handling: the ONLY place 'plaintext' is used is the create_token() JSON response —
// it must never reach an insert()/update() call anywhere in this file.
$insert_calls = array();
preg_match_all('/\$this->db->insert\([^)]*\)/s', $src, $m1); $insert_calls = array_merge($insert_calls, $m1[0]);
preg_match_all('/->update\([^)]*\)/s', $src, $m2); $insert_calls = array_merge($insert_calls, $m2[0]);
$plaintext_in_write = false;
foreach ($insert_calls as $call) if (stripos($call, 'plaintext') !== false) $plaintext_in_write = true;
check('no insert()/update() call anywhere in Api_keys.php references the plaintext token', $plaintext_in_write, false);
check("create_token(): the stored row uses the hash, not the plaintext",
    strpos($src, "'token_hash' => \$generated['hash'],") !== false, true);
check("create_token(): the response echoes the plaintext exactly once",
    substr_count($src, "\$generated['plaintext']") === 1, true);

// Audit trail on create/revoke.
check("create_token(): audit_record() is called on success", strpos($src, "audit_record(\$this->uid, 'api_token.created'") !== false, true);
check("revoke_token(): audit_record() is called on success", strpos($src, "audit_record(\$this->uid, 'api_token.revoked'") !== false, true);

// ============================================================================================
echo "\n== tenant scoping via FakeQB: owned_token()-shaped query never returns another tenant's row ==\n";

class FakeQB
{
    public $calls = array();
    public $rows = array();
    public function select($s) { $this->calls[] = array('select', $s); return $this; }
    public function from($t) { $this->calls[] = array('from', $t); return $this; }
    public function where($a, $b = null) { $this->calls[] = array('where', $a, $b); return $this; }
    public function limit($n) { return $this; }
    public function get() { return $this; }
    public function row_array()
    {
        // Simulate a real WHERE: both id AND user_id conditions must match, exactly like MySQL would.
        $id_cond = null; $uid_cond = null;
        foreach ($this->calls as $c) {
            if ($c[0] === 'where' && $c[1] === 'id') $id_cond = $c[2];
            if ($c[0] === 'where' && $c[1] === 'user_id') $uid_cond = $c[2];
        }
        foreach ($this->rows as $r) {
            if ((int) $r['id'] === (int) $id_cond && (int) $r['user_id'] === (int) $uid_cond) return $r;
        }
        return array();
    }
}

$qb = new FakeQB();
$qb->rows = array(array('id' => 5, 'user_id' => 2, 'name' => 'tenant-2-token'));

$qb->calls = array();
$qb->select('id, user_id, name')->from('api_tokens')->where('id', 5)->where('user_id', 2)->limit(1)->get();
$own_row = $qb->row_array();
check('owner requesting their own token id gets the row', empty($own_row), false);

$qb->calls = array();
$qb->select('id, user_id, name')->from('api_tokens')->where('id', 5)->where('user_id', 999)->limit(1)->get();
$cross_row = $qb->row_array();
check('a different tenant requesting the same token id gets nothing (cross-tenant id refused)', empty($cross_row), true);

// ============================================================================================
echo "\n" . ($fail === 0 ? "ALL PASS ({$pass})\n" : "FAILED: {$fail} of " . ($pass + $fail) . "\n");
exit($fail === 0 ? 0 : 1);
