<?php
/**
 * Client provisioning unit tests. No CodeIgniter, no database, no network.
 *
 *   php tests/provisioning/run.php
 *   docker exec -u www-data <app> php /var/www/html/tests/provisioning/run.php
 *
 * provisioning_helper.php's two DB-bound entry points (provisioning_prepare_client,
 * provisioning_create_client) take an injectable $db — same pattern as
 * pricing_helper.php's pricing_save_config() and tests/sequences/run.php's FakeDb. FakeDb
 * below implements only what provisioning_helper.php actually calls: select/from/where/
 * limit/get/row_array, insert/insert_id, and a real rollback-capable
 * trans_start/trans_rollback/trans_complete/trans_status.
 *
 * What THIS suite covers: field validation (business name, email format), duplicate-email
 * rejection, unknown/invalid package_id rejection, unknown template_key rejection, the exact
 * expired_date formula, password generation strength + uniqueness, and — the one that needs a
 * real (fake) transaction — that a template which fails its defense-in-depth recheck INSIDE
 * the transaction rolls back the just-inserted users row too, leaving zero rows in both
 * tables.
 *
 * What is NOT here, deliberately: CSRF/admin-only gating on Agency_provision, the real
 * industry_template_helper.php file-based loader, an actual password login. Those are proven
 * end-to-end on a live stack by docker/scripts/staging_provisioning_tests.sh.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
date_default_timezone_set('UTC');
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

require __DIR__ . '/../../application/helpers/password_helper.php';
require __DIR__ . '/../../application/helpers/provisioning_helper.php';

// ---------------------------------------------------------------- tiny harness

$PASS = 0; $FAIL = 0;
function ok($name) { global $PASS; $PASS++; echo "PASS  $name\n"; }
function bad($name, $why) { global $FAIL; $FAIL++; echo "FAIL  $name  -> $why\n"; }
function is_same($name, $expected, $actual) {
    if ($expected === $actual) ok($name);
    else bad($name, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function is_true($name, $v) { is_same($name, true, $v); }
function is_false($name, $v) { is_same($name, false, $v); }
function contains($name, $haystack, $needle) {
    if (strpos((string) $haystack, $needle) !== false) ok($name);
    else bad($name, "'$needle' not found in " . var_export($haystack, true));
}

// ---------------------------------------------------------------- fake industry_template_* (no filesystem dependency)

$FAKE_TEMPLATES = array(
    'hotel'  => array('key' => 'hotel', 'display_name' => array('en' => 'Hotel', 'ar' => 'فندق')),
    // 'broken' LOADS successfully both times (so provisioning_prepare_client()'s pre-check
    // passes) but its validate() call is rigged to fail only the SECOND time it runs --
    // simulating the exact race/corruption the defense-in-depth recheck (inside the
    // transaction, after the users row is already inserted) exists to catch.
    'broken' => array('key' => 'broken', 'display_name' => array('en' => 'Broken', 'ar' => 'معطل')),
);
function industry_template_load($key) {
    global $FAKE_TEMPLATES;
    $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
    return isset($FAKE_TEMPLATES[$key]) ? $FAKE_TEMPLATES[$key] : null;
}
$GLOBALS['__validate_calls'] = array();
function industry_template_validate($tpl) {
    $key = is_array($tpl) ? ($tpl['key'] ?? '') : '';
    if ($key !== 'broken') return array();
    $GLOBALS['__validate_calls'][$key] = ($GLOBALS['__validate_calls'][$key] ?? 0) + 1;
    return $GLOBALS['__validate_calls'][$key] >= 2 ? array('forced invalid on recheck') : array();
}

// ---------------------------------------------------------------- fake DB (CI query-builder-alike)

class FakeResult
{
    private $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function row_array() { return isset($this->rows[0]) ? $this->rows[0] : null; }
    public function result_array() { return array_values($this->rows); }
}

class FakeDb
{
    public $tables = array();
    public $auto = array();
    private $table; private $wheres = array(); private $lim = null;
    private $last_insert_id = 0;
    private $trans_snapshot_tables = null; private $trans_snapshot_auto = null; private $trans_failed = false;

    public function select($c) { return $this; }
    public function from($t) { $this->table = $t; $this->wheres = array(); $this->lim = null; return $this; }
    public function where($a, $b = null) { $this->wheres[] = array($a, $b); return $this; }
    public function limit($n) { $this->lim = $n; return $this; }

    private function reset_builder() { $this->wheres = array(); $this->lim = null; }

    public function get()
    {
        $rows = isset($this->tables[$this->table]) ? array_values($this->tables[$this->table]) : array();
        foreach ($this->wheres as $w) {
            list($k, $v) = $w;
            if ($v === null) continue;
            $rows = array_values(array_filter($rows, function ($r) use ($k, $v) {
                return array_key_exists($k, $r) && (string) $r[$k] === (string) $v;
            }));
        }
        if ($this->lim !== null) $rows = array_slice($rows, 0, $this->lim);
        $this->reset_builder();
        return new FakeResult($rows);
    }

    public function insert($table, array $data)
    {
        if (!isset($this->tables[$table])) { $this->tables[$table] = array(); $this->auto[$table] = 1; }
        $id = $this->auto[$table]++;
        $data['id'] = $id;
        $this->tables[$table][$id] = $data;
        $this->last_insert_id = $id;
        $this->reset_builder();
    }

    public function insert_id() { return $this->last_insert_id; }

    // --- transactions: real rollback semantics, so provisioning_create_client()'s
    //     defense-in-depth abort can be proven to leave zero rows behind.
    public function trans_start()
    {
        $this->trans_snapshot_tables = $this->tables;
        $this->trans_snapshot_auto = $this->auto;
        $this->trans_failed = false;
    }
    public function trans_rollback()
    {
        $this->tables = $this->trans_snapshot_tables;
        $this->auto = $this->trans_snapshot_auto;
        $this->trans_failed = true;
    }
    public function trans_complete() { /* no-op: nothing failed, keep the writes */ }
    public function trans_status() { return !$this->trans_failed; }

    public function count_all($table) { return isset($this->tables[$table]) ? count($this->tables[$table]) : 0; }
}

// ---------------------------------------------------------------- fixtures

function fresh_db()
{
    $db = new FakeDb();
    $db->tables['package'] = array(
        1 => array('id' => 1, 'package_name' => 'Starter', 'validity' => 30, 'deleted' => '0'),
        2 => array('id' => 2, 'package_name' => 'Retired', 'validity' => 30, 'deleted' => '1'),
    );
    $db->auto['package'] = 3;
    $db->tables['users'] = array(
        9 => array('id' => 9, 'name' => 'Existing Client', 'email' => 'taken@client.test'),
    );
    $db->auto['users'] = 10;
    return $db;
}

function base_input($over = array())
{
    return array_merge(array(
        'business_name' => 'Sunrise Clinic',
        'email'         => 'new@client.test',
        'package_id'    => 1,
        'template_key'  => '',
    ), $over);
}

// ============================================================ validation

$db = fresh_db();
list($errors, $ctx) = provisioning_prepare_client($db, base_input(array('business_name' => '  ')));
is_true('empty business name rejected', in_array('Business name is required.', $errors, true));

list($errors, $ctx) = provisioning_prepare_client($db, base_input(array('email' => 'not-an-email')));
is_true('malformed email rejected', in_array('Enter a valid email address.', $errors, true));

list($errors, $ctx) = provisioning_prepare_client($db, base_input(array('email' => 'taken@client.test')));
is_true('duplicate email rejected with a clear message', in_array('A client with this email already exists.', $errors, true));

list($errors, $ctx) = provisioning_prepare_client($db, base_input(array('package_id' => 0)));
is_true('missing package rejected', in_array('Choose a package.', $errors, true));

list($errors, $ctx) = provisioning_prepare_client($db, base_input(array('package_id' => 999)));
is_true('unknown package_id rejected', in_array('Unknown package. Pick one from the list.', $errors, true));

list($errors, $ctx) = provisioning_prepare_client($db, base_input(array('package_id' => 2)));
is_true('deleted package rejected (not offerable)', in_array('Unknown package. Pick one from the list.', $errors, true));

list($errors, $ctx) = provisioning_prepare_client($db, base_input(array('template_key' => 'not_a_real_template')));
is_true('unknown template_key rejected', in_array('Unknown business type.', $errors, true));

list($errors, $ctx) = provisioning_prepare_client($db, base_input());
is_same('valid input has zero errors', array(), $errors);
is_same('valid input resolves the package row', 1, $ctx['package']['id'] ?? null);

// ============================================================ expiry formula (matches Home::sign_up_action)

list($ok, $errs, $result) = provisioning_create_client(fresh_db(), 1, base_input(array('email' => 'expiry@client.test', 'package_id' => 1)));
is_true('create succeeds with valid input', $ok);
$expected_expiry = date('Y-m-d', strtotime('+30 day', strtotime(date('Y-m-d'))));
is_same('expired_date = today + package validity (stock signup formula)', $expected_expiry, $result['expired_date'] ?? null);

// ============================================================ users row conventions

$db = fresh_db();
list($ok, $errs, $result) = provisioning_create_client($db, 7, base_input(array('email' => 'conv@client.test')));
is_true('create succeeds', $ok);
$row = $db->tables['users'][$result['user_id']];
is_same('user_type = Member', 'Member', $row['user_type']);
is_same('status = 1 (active immediately, no activation email)', '1', $row['status']);
is_same('deleted = 0', '0', $row['deleted']);
is_same('provisioned_by = the acting admin', 7, $row['provisioned_by']);
is_true('password is hashed, not plaintext', $row['password'] !== $result['temp_password'] && strlen($row['password']) > 20);
is_true('the plaintext password logs the account in (password_verify)', password_verify($result['temp_password'], $row['password']));
is_same('no onboarding_state row when no template chosen', 0, $db->count_all('onboarding_state'));

// ============================================================ template pre-seeds onboarding_state

$db = fresh_db();
list($ok, $errs, $result) = provisioning_create_client($db, 1, base_input(array('email' => 'tpl@client.test', 'template_key' => 'hotel')));
is_true('create with template succeeds', $ok);
is_same('one onboarding_state row written', 1, $db->count_all('onboarding_state'));
$st = array_values($db->tables['onboarding_state'])[0];
is_same('onboarding_state.user_id = the new client', $result['user_id'], $st['user_id']);
is_same('onboarding_state.current_step = 1 (same shape as Onboarding::state())', 1, $st['current_step']);
is_same('onboarding_state.template_key = the chosen template', 'hotel', $st['template_key']);
is_same('onboarding_state.data_json = {} (blank, matches a fresh wizard state)', '{}', $st['data_json']);

// ============================================================ transaction rollback — forced mid-transaction failure

$db = fresh_db();
$before_users = $db->count_all('users');
list($ok, $errs, $result) = provisioning_create_client($db, 1, base_input(array('email' => 'rollback@client.test', 'template_key' => 'broken')));
// 'broken' passes provisioning_prepare_client()'s pre-check (industry_template_load finds it)
// but fails industry_template_validate() on the defense-in-depth recheck INSIDE the
// transaction -- exactly the "would-be-valid user row, then an invalid template" scenario.
is_false('create is rejected when the defense-in-depth recheck fails', $ok);
is_same('users table has zero NEW rows after rollback', $before_users, $db->count_all('users'));
is_same('onboarding_state table has zero rows after rollback', 0, $db->count_all('onboarding_state'));
is_true('rollback error message says nothing was saved', strpos($errs[0] ?? '', 'Nothing was saved') !== false);

// ============================================================ password generation

$pw = provisioning_generate_password();
is_true('generated password length >= 12', strlen($pw) >= 12);
is_true('generated password has a lowercase letter', (bool) preg_match('/[a-z]/', $pw));
is_true('generated password has an uppercase letter', (bool) preg_match('/[A-Z]/', $pw));
is_true('generated password has a digit', (bool) preg_match('/[0-9]/', $pw));
is_true('generated password has a symbol', (bool) preg_match('/[!@#$%^&*\-_=+?]/', $pw));

$seen = array();
for ($i = 0; $i < 200; $i++) $seen[provisioning_generate_password()] = true;
is_same('200 generated passwords are all unique', 200, count($seen));

echo "== $PASS passed, $FAIL failed ==\n";
exit($FAIL === 0 ? 0 : 1);
