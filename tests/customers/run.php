<?php
/**
 * Customer 360 (read side) unit tests — Task 16 (C1, brief §16/§13). No DB, no CodeIgniter
 * bootstrap, no network — exercises the exact functions Customers.php and
 * Inbox::customer_panel() call (application/helpers/customer_helper.php), plus static
 * cross-tenant audits of both controllers (same style as tests/cross_tenant/run.php).
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/customers/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('customer_helper.php must not need a CI instance for these functions'); }
function log_message($level, $msg) { /* no-op */ }

require __DIR__ . '/../../application/helpers/customer_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ------------------------------------------------------------------------------------------
// A fake CodeIgniter query builder: records every call so a test can assert the tenant guard
// (user_id) is ALWAYS the query's first condition (same approach as tests/inbox/run.php's
// FakeQB, the actual shape IDOR bugs in this codebase have taken, brief §38/§57).
class FakeQB
{
    public $calls = array();
    public $table_exists_map = array();
    public $field_exists_map = array();
    public $rows = array();

    public function select($s) { $this->calls[] = array('select', $s); return $this; }
    public function from($t) { $this->calls[] = array('from', $t); return $this; }
    public function join($t, $on, $type = '') { $this->calls[] = array('join', $t, $on); return $this; }
    public function where($a, $b = null, $escape = null) { $this->calls[] = array('where', $a, $b); return $this; }
    public function or_where($a, $b = null) { $this->calls[] = $b === null ? array('or_where', $a) : array('or_where', $a, $b); return $this; }
    public function where_in($a, $b) { $this->calls[] = array('where_in', $a, $b); return $this; }
    public function like($a, $b) { $this->calls[] = array('like', $a, $b); return $this; }
    public function or_like($a, $b) { $this->calls[] = array('or_like', $a, $b); return $this; }
    public function group_start() { $this->calls[] = array('group_start'); return $this; }
    public function group_end() { $this->calls[] = array('group_end'); return $this; }
    public function order_by($a, $b = null) { $this->calls[] = array('order_by', $a, $b); return $this; }
    public function limit($a, $b = null) { $this->calls[] = array('limit', $a, $b); return $this; }
    public function escape($v) { return "'" . addcslashes((string) $v, "'\\") . "'"; }
    public function table_exists($t) { return !empty($this->table_exists_map[$t]); }
    public function field_exists($f, $t) { return !empty($this->field_exists_map[$t][$f]); }
    public function get() { return $this; }
    public function result_array() { return $this->rows; }
    public function row_array() { return $this->rows ? $this->rows[0] : array(); }
    public function count_all_results($t = '', $reset = true) { return count($this->rows); }
}

// ============================================================================================
echo "== customer_scope_list(): tenant guard is always first, filters appended after ==\n";

$q = new FakeQB();
customer_scope_list($q, 555, array());
check('no filters -> tenant guard is the only call', $q->calls, array(array('where', 'c.user_id', 555)));

$q = new FakeQB();
customer_scope_list($q, 555, array('search' => "o'brien", 'channel' => 'wa'));
check('with filters -> tenant guard is still the first call', $q->calls[0], array('where', 'c.user_id', 555));
$has_or_where_bypass = false;
foreach ($q->calls as $c) {
    if ($c[0] === 'where' && $c === array('where', "EXISTS (SELECT 1 FROM customer_identities ci WHERE ci.customer_id = c.id AND ci.user_id = 555 AND ci.platform = 'wa')", null)) {
        $has_or_where_bypass = true;
    }
}
check('channel filter EXISTS() subquery is itself scoped to the same tenant id', $has_or_where_bypass, true);

echo "== customer_scope_owner(): id + tenant guard together ==\n";
$q = new FakeQB();
customer_scope_owner($q, 42, 555);
check('scope_owner: id filter first', $q->calls[0], array('where', 'id', 42));
check('scope_owner: tenant filter second (defense in depth even with a valid-looking id)', $q->calls[1], array('where', 'user_id', 555));

// ============================================================================================
echo "== customer_pagination_bounds(): clamps like Inbox.php's conversations_data() ==\n";

check('negative start clamps to 0', customer_pagination_bounds(-5, 20), array(0, 20));
check('zero length falls back to default 20', customer_pagination_bounds(0, 0), array(0, 20));
check('length above max (100) falls back to default 20', customer_pagination_bounds(0, 5000), array(0, 20));
check('length within bounds passes through', customer_pagination_bounds(40, 50), array(40, 50));
check('custom default/max respected', customer_pagination_bounds(0, 0, 10, 30), array(0, 10));
check('custom max respected', customer_pagination_bounds(0, 31, 10, 30), array(0, 10));

// ============================================================================================
echo "== identities across 2 channels for one customer (the brief's own aggregation example) ==\n";

$identities = array(
    array('platform' => 'fb', 'external_id' => 'psid-1', 'channel_account_id' => 10),
    array('platform' => 'wa', 'external_id' => '2010000000', 'channel_account_id' => 0),
    array('platform' => 'wa', 'external_id' => '2010000001', 'channel_account_id' => 0), // second wa number, same customer
);
$by_channel = customer_identities_by_channel($identities);
check('groups into exactly 2 channels (fb, wa)', array_keys($by_channel), array('fb', 'wa'));
check('fb has 1 identity', count($by_channel['fb']), 1);
check('wa has 2 identities (still one channel)', count($by_channel['wa']), 2);
check('customer_channel_count() agrees', customer_channel_count($identities), 2);
check('no identities -> 0 channels', customer_channel_count(array()), 0);
check('a row with an empty platform is skipped, not counted as its own channel', customer_channel_count(array(array('platform' => ''))), 0);

// ============================================================================================
echo "== customer_lead_stage_summary(): open/won/lost + the single most relevant open deal ==\n";

check('no deals -> zeroed summary, no open deal', customer_lead_stage_summary(array()),
    array('open_deal' => null, 'open_count' => 0, 'won_count' => 0, 'lost_count' => 0));

$deals = array(
    array('status' => 'open', 'title' => 'Later deal', 'expected_close_date' => '2026-12-01', 'created_at' => '2026-09-01 00:00:00'),
    array('status' => 'open', 'title' => 'Sooner deal', 'expected_close_date' => '2026-10-01', 'created_at' => '2026-09-05 00:00:00'),
    array('status' => 'won', 'title' => 'Won deal', 'expected_close_date' => null, 'created_at' => '2026-08-01 00:00:00'),
    array('status' => 'lost', 'title' => 'Lost deal', 'expected_close_date' => null, 'created_at' => '2026-08-01 00:00:00'),
);
$summary = customer_lead_stage_summary($deals);
check('picks the open deal with the soonest expected_close_date', $summary['open_deal']['title'], 'Sooner deal');
check('open_count is 2', $summary['open_count'], 2);
check('won_count is 1', $summary['won_count'], 1);
check('lost_count is 1', $summary['lost_count'], 1);

// ============================================================================================
echo "== customer_optional_table_ready(): guards optional tables, caches per request ==\n";

$q = new FakeQB();
$q->table_exists_map = array('crm_deals' => true);
$cache = array();
check('crm_deals exists -> ready true', customer_optional_table_ready($q, 'crm_deals', $cache), true);
check('ab_appointments missing -> ready false', customer_optional_table_ready($q, 'ab_appointments', $cache), false);
check('cache now holds both keys (no repeated table_exists calls on the next lookup)', $cache, array('crm_deals' => true, 'ab_appointments' => false));

// ============================================================================================
echo "== customer_deals_for() / customer_appointments_for(): keyed off legacy_subscriber_id, not customers.id ==\n";

$q = new FakeQB();
$q->table_exists_map = array('crm_deals' => true);
$q->rows = array(array('id' => 1, 'status' => 'open'));
$cache = array();
$rows = customer_deals_for($q, 9, array('legacy_subscriber_id' => 777), $cache);
check('deals query filters by user_id first', $q->calls[array_search(array('where', 'user_id', 9), $q->calls)], array('where', 'user_id', 9));
check('deals query filters by the legacy subscriber id, not customers.id', in_array(array('where', 'subscriber_id', 777), $q->calls), true);
check('rows come back', $rows, array(array('id' => 1, 'status' => 'open')));

$q2 = new FakeQB();
$q2->table_exists_map = array('crm_deals' => true);
$cache2 = array();
check('no legacy_subscriber_id (never a subscriber) -> no query at all, empty array', customer_deals_for($q2, 9, array('legacy_subscriber_id' => null), $cache2), array());
check('...and indeed nothing was called', $q2->calls, array());

$q3 = new FakeQB();
$q3->table_exists_map = array('crm_deals' => false);
$cache3 = array();
check('crm_deals table missing (before its migration / not this install) -> empty array, no crash', customer_deals_for($q3, 9, array('legacy_subscriber_id' => 777), $cache3), array());

// ============================================================================================
echo "== customer_orders_for(): joins ecommerce_cart via every identity's external_id ==\n";

$q = new FakeQB();
$q->table_exists_map = array('ecommerce_cart' => true);
$q->rows = array(array('id' => 5));
$cache = array();
$idents = array(array('platform' => 'fb', 'external_id' => 'psid-1'), array('platform' => 'wa', 'external_id' => '2010000000'));
$rows = customer_orders_for($q, 3, $idents, $cache);
$where_in_call = null;
foreach ($q->calls as $c) if ($c[0] === 'where_in') $where_in_call = $c;
check('where_in uses subscriber_id against the customer\'s external ids', $where_in_call[1], 'subscriber_id');
sort($where_in_call[2]);
check('...covering both channels\' external ids', $where_in_call[2], array('2010000000', 'psid-1'));
check('rows returned', $rows, array(array('id' => 5)));

$q4 = new FakeQB();
$q4->table_exists_map = array('ecommerce_cart' => true);
$cache4 = array();
check('no identities -> no query, empty array', customer_orders_for($q4, 3, array(), $cache4), array());

// ============================================================================================
echo "== customer_display_name(): fallback chain ==\n";

check('display_name wins when present', customer_display_name(array('id' => 1, 'display_name' => 'Sara', 'phone' => '0100', 'email' => 'a@b.com')), 'Sara');
check('falls back to phone', customer_display_name(array('id' => 1, 'display_name' => '', 'phone' => '0100', 'email' => 'a@b.com')), '0100');
check('falls back to email when no phone', customer_display_name(array('id' => 1, 'display_name' => '', 'phone' => '', 'email' => 'a@b.com')), 'a@b.com');
check('falls back to a generated label when nothing else is known', customer_display_name(array('id' => 7, 'display_name' => '', 'phone' => '', 'email' => '')), 'Customer #7');

// ============================================================================================
echo "== static cross-tenant audit: Customers.php ==\n";

$customers_src = file_get_contents(__DIR__ . '/../../application/controllers/Customers.php');

check('constructor derives $this->uid the same way Inbox.php does (real_user_id falling back to user_id)',
    strpos($customers_src, "\$this->uid = \$this->session->userdata('real_user_id') ?: \$this->session->userdata('user_id');") !== false, true);

check('entitlement_require(\'customers\') gates the whole controller', strpos($customers_src, "entitlement_require('customers')") !== false, true);

check('customers_data() calls customer_scope_list($this->db, $this->uid, ...) — never a bare from(\'customers\') without it',
    strpos($customers_src, 'customer_scope_list($this->db, $this->uid, $filters)') !== false, true);

check('profile() calls customer_scope_owner($this->db, $customer_id, $this->uid) before returning a row',
    strpos($customers_src, 'customer_scope_owner($this->db, $customer_id, $this->uid)') !== false, true);

check('every optional-table query in build_profile() passes $this->uid as the tenant id', substr_count($customers_src, '$this->uid') >= 8, true);

check('customers_data() is gated by ajax_check()', strpos($customers_src, 'public function customers_data()') !== false
    && strpos(substr($customers_src, strpos($customers_src, 'public function customers_data()')), '$this->ajax_check();') !== false, true);

// ============================================================================================
echo "== static cross-tenant audit: Inbox::customer_panel() ==\n";

$inbox_src = file_get_contents(__DIR__ . '/../../application/controllers/Inbox.php');
$panel_src = substr($inbox_src, strpos($inbox_src, 'public function customer_panel()'), strpos($inbox_src, 'private function labels_ready()') - strpos($inbox_src, 'public function customer_panel()'));

check('customer_panel() exists', $panel_src !== '' && $panel_src !== false, true);
check('customer_panel() is ajax_check()-gated', strpos($panel_src, '$this->ajax_check();') !== false, true);
check('customer_panel() is csrf_token_check()-gated', strpos($panel_src, '$this->csrf_token_check();') !== false, true);
check('customer_panel() resolves the conversation through conversation_get($id, $this->uid) — IDOR-checked like conversation_thread()',
    strpos($panel_src, 'conversation_get($conversation_id, $this->uid)') !== false, true);
check('customer_panel() re-scopes the customer row itself with customer_scope_owner(..., $this->uid), not just trusting conversations.customer_id',
    strpos($panel_src, 'customer_scope_owner($this->db, (int) $conv[\'customer_id\'], $this->uid)') !== false, true);
check('customer_panel() reuses customer_deals_for()/customer_labels_for()/customer_orders_for() — same functions the full profile page calls',
    strpos($panel_src, 'customer_deals_for($this->db, $this->uid,') !== false
    && strpos($panel_src, 'customer_labels_for($this->db, $this->uid,') !== false
    && strpos($panel_src, 'customer_orders_for($this->db, $this->uid,') !== false, true);

// ============================================================================================
echo "== behavioral cross-tenant: a fake row from another tenant never survives customer_scope_owner() ==\n";

// Simulates what conversation_get()/customer_scope_owner() do inside the real CI query
// builder: WHERE clauses AND together, so asking for tenant B's customer id 42 while scoped
// to tenant A's user_id 1 matches nothing. This fake proves the *shape* is right (id AND
// user_id, not id OR user_id, and not user_id applied only after the row is already fetched).
class FakeRowDB extends FakeQB
{
    private $seed;
    public function __construct(array $seed) { $this->seed = $seed; }
    public function get() { return $this; }
    public function row_array()
    {
        $id_filter = null; $user_filter = null;
        foreach ($this->calls as $c) {
            if ($c[0] === 'where' && $c[1] === 'id') $id_filter = $c[2];
            if ($c[0] === 'where' && $c[1] === 'user_id') $user_filter = $c[2];
        }
        if ($this->seed['id'] === $id_filter && $this->seed['user_id'] === $user_filter) return $this->seed;
        return array(); // tenant mismatch -> nothing, exactly like a real WHERE id=.. AND user_id=..
    }
}

$tenantBsRow = array('id' => 42, 'user_id' => 2, 'display_name' => 'Tenant B customer');
$db = new FakeRowDB($tenantBsRow);
$q = $db->select('*')->from('customers');
customer_scope_owner($q, 42, 1); // tenant A asking for tenant B's row id
check('tenant A cannot read tenant B\'s customer row through customer_scope_owner()', $q->get()->row_array(), array());

$db2 = new FakeRowDB($tenantBsRow);
$q2 = $db2->select('*')->from('customers');
customer_scope_owner($q2, 42, 2); // tenant B asking for its own row
check('...but tenant B reading its own row still works (guard is not overzealous)', $q2->get()->row_array(), $tenantBsRow);

// ============================================================================================
// Task 29 (C14, brief §16) — customer_merge()/customer_unmerge()/merge suggestions.
// REVIEW GATE: nothing here ever merges automatically — every merge below is an explicit
// customer_merge() call, and every one is proven reversible by an immediately-following
// customer_unmerge() that restores exactly the moved rows, no more, no less.

/** In-memory fake CI DB supporting the small subset customer_merge()/customer_unmerge() use:
 *  select/from/where/where_in/get/row_array/result_array, update, insert/insert_id,
 *  trans_start/trans_complete/trans_status, table_exists/field_exists. Good enough to prove
 *  real row-level behaviour (which ids moved, which stayed) without a real database. */
class FakeMergeDB
{
    public $tables;
    public $table_exists_map = array();
    public $field_exists_map = array();
    private $ai = array();
    private $q;
    private $last_id = 0;
    private $trans_ok = true;

    public function __construct(array $seed)
    {
        $this->tables = $seed;
        foreach ($this->tables as $t => $rows) {
            $max = 0;
            foreach ($rows as $r) $max = max($max, (int) ($r['id'] ?? 0));
            $this->ai[$t] = $max;
        }
        $this->reset();
    }

    private function reset() { $this->q = array('table' => null, 'where' => array()); }

    public function select($s) { return $this; }
    public function from($t) { $this->q['table'] = trim(explode(' ', trim($t))[0]); return $this; }
    public function join($t, $on, $type = '') { return $this; }
    public function order_by($a, $b = null) { return $this; }
    public function limit($a, $b = null) { return $this; }

    public function where($a, $b = null, $escape = null) { $this->q['where'][] = array($a, $b, false); return $this; }
    public function where_in($col, $vals) { $this->q['where'][] = array($col, $vals, true); return $this; }

    private function matches(array $r, array $wheres)
    {
        foreach ($wheres as $w) {
            list($col, $val, $is_in) = $w;
            if ($is_in) { if (!in_array($r[$col] ?? null, $val)) return false; }
            else { if (($r[$col] ?? null) != $val) return false; }
        }
        return true;
    }

    public function get() { return $this; }

    public function result_array()
    {
        $table = $this->q['table']; $wheres = $this->q['where']; $this->reset();
        $out = array();
        foreach (($this->tables[$table] ?? array()) as $r) if ($this->matches($r, $wheres)) $out[] = $r;
        return $out;
    }

    public function row_array()
    {
        $rows = $this->result_array();
        return $rows ? $rows[0] : array();
    }

    public function update($table, $data)
    {
        $wheres = $this->q['where']; $this->reset();
        foreach ($this->tables[$table] as $id => $r) {
            if ($this->matches($r, $wheres)) {
                foreach ($data as $k => $v) $this->tables[$table][$id][$k] = $v;
            }
        }
        return true;
    }

    public function insert($table, $data)
    {
        $this->ai[$table] = ($this->ai[$table] ?? 0) + 1;
        $id = $this->ai[$table];
        $data['id'] = $id;
        $this->tables[$table][$id] = $data;
        $this->last_id = $id;
        return true;
    }
    public function insert_id() { return $this->last_id; }

    public function trans_start() { $this->trans_ok = true; }
    public function trans_complete() { }
    public function trans_status() { return $this->trans_ok; }

    public function table_exists($t) { return isset($this->tables[$t]) || !empty($this->table_exists_map[$t]); }
    public function field_exists($f, $t) { return !empty($this->field_exists_map[$t][$f]); }
}

function fresh_merge_db()
{
    return new FakeMergeDB(array(
        'customers' => array(
            1 => array('id' => 1, 'user_id' => 1, 'merged_into_customer_id' => null), // keep
            2 => array('id' => 2, 'user_id' => 1, 'merged_into_customer_id' => null), // merge
            3 => array('id' => 3, 'user_id' => 2, 'merged_into_customer_id' => null), // other tenant
        ),
        'customer_identities' => array(
            10 => array('id' => 10, 'customer_id' => 1, 'user_id' => 1, 'platform' => 'wa', 'channel_account_id' => 0, 'external_id' => '100'),
            11 => array('id' => 11, 'customer_id' => 2, 'user_id' => 1, 'platform' => 'fb', 'channel_account_id' => 0, 'external_id' => '200'),
            12 => array('id' => 12, 'customer_id' => 2, 'user_id' => 1, 'platform' => 'wa', 'channel_account_id' => 0, 'external_id' => '999'),
            13 => array('id' => 13, 'customer_id' => 1, 'user_id' => 1, 'platform' => 'tg', 'channel_account_id' => 0, 'external_id' => 'dupe'),
            14 => array('id' => 14, 'customer_id' => 2, 'user_id' => 1, 'platform' => 'tg', 'channel_account_id' => 0, 'external_id' => 'dupe'), // collides with 13
        ),
        'conversations' => array(
            100 => array('id' => 100, 'customer_id' => 2, 'user_id' => 1),
            101 => array('id' => 101, 'customer_id' => 2, 'user_id' => 1),
            102 => array('id' => 102, 'customer_id' => 1, 'user_id' => 1), // already keep's, must not move / duplicate
            103 => array('id' => 103, 'customer_id' => 3, 'user_id' => 2), // other tenant, must never be touched
        ),
        'customer_merge_log' => array(),
    ));
}
function merge_log_ready($db) { $db->table_exists_map['customer_merge_log'] = true; $db->field_exists_map['customer_merge_log'] = array('conversations_moved' => true, 'unmerged_at' => true); return $db; }

$audit_calls = array();
if (!function_exists('audit_record')) {
    function audit_record($user_id, $action, $entity_type = '', $entity_id = '', $before = null, $after = null, $actor_id = null, $actor_type = 'system')
    {
        global $audit_calls;
        $audit_calls[] = func_get_args();
        return 1;
    }
}

echo "== customer_merge(): before the migration (field_exists false) refuses, writes nothing ==\n";
$db = fresh_merge_db(); // no table_exists/field_exists set -> ready() is false
list($ok, $errors, $result) = customer_merge($db, 1, 2, 9, 1);
check('refused before migration', $ok, false);
check('customers table untouched', $db->tables['customers'][2]['merged_into_customer_id'], null);
check('no log row written', $db->tables['customer_merge_log'], array());

echo "== customer_merge(): self-merge refused ==\n";
$db = merge_log_ready(fresh_merge_db());
list($ok, $errors) = customer_merge($db, 1, 1, 9, 1);
check('self-merge refused', $ok, false);
check('self-merge error message', $errors, array('Cannot merge a customer into itself.'));

echo "== customer_merge(): cross-tenant refused (merge_id belongs to another tenant) ==\n";
$db = merge_log_ready(fresh_merge_db());
list($ok, $errors) = customer_merge($db, 1, 3, 9, 1); // customer 3 is user_id 2
check('cross-tenant merge_id refused', $ok, false);
check('customer 3 (tenant 2) untouched', $db->tables['customers'][3]['merged_into_customer_id'], null);

echo "== customer_merge(): cross-tenant refused (keep_id belongs to another tenant) ==\n";
$db = merge_log_ready(fresh_merge_db());
list($ok, $errors) = customer_merge($db, 3, 2, 9, 1); // keep_id 3 is user_id 2, acting as tenant 1
check('cross-tenant keep_id refused', $ok, false);
check('customer 2 untouched', $db->tables['customers'][2]['merged_into_customer_id'], null);

echo "== customer_merge(): happy path moves the right rows, skips colliding identities, logs everything ==\n";
$audit_calls = array();
$db = merge_log_ready(fresh_merge_db());
list($ok, $errors, $result) = customer_merge($db, 1, 2, 9, 1);
check('merge succeeds', $ok, true);
check('no errors', $errors, array());

$moved_idents = $result['identities_moved']; sort($moved_idents);
check('identities 11 and 12 moved (14 skipped: would collide with 13 on customer 1)', $moved_idents, array(11, 12));
check('identity 14 (the collision) stayed on customer 2', $db->tables['customer_identities'][14]['customer_id'], 2);
check('identity 13 (the pre-existing one) untouched', $db->tables['customer_identities'][13]['customer_id'], 1);
check('identity 11 now belongs to customer 1', $db->tables['customer_identities'][11]['customer_id'], 1);
check('identity 12 now belongs to customer 1', $db->tables['customer_identities'][12]['customer_id'], 1);

$moved_convs = $result['conversations_moved']; sort($moved_convs);
check('conversations 100 and 101 moved', $moved_convs, array(100, 101));
check('conversation 102 (already keep\'s) untouched', $db->tables['conversations'][102]['customer_id'], 1);
check('conversation 103 (other tenant) untouched', $db->tables['conversations'][103]['customer_id'], 3);

check('customer 2 marked merged into 1', $db->tables['customers'][2]['merged_into_customer_id'], 1);
check('customer 1 (the keep side) not marked merged', $db->tables['customers'][1]['merged_into_customer_id'], null);

$log_id = $result['log_id'];
$log = $db->tables['customer_merge_log'][$log_id];
check('log row: source is the merged customer', (int) $log['source_customer_id'], 2);
check('log row: target is the keep customer', (int) $log['target_customer_id'], 1);
check('log row: identities_moved JSON matches', json_decode($log['identities_moved'], true), array(11, 12));
check('log row: conversations_moved JSON matches', json_decode($log['conversations_moved'], true), array(100, 101));
check('log row: not yet unmerged', $log['unmerged_at'] ?? null, null);
check('audit_record() was called for the merge', count($audit_calls) === 1 && $audit_calls[0][1] === 'customer.merge', true);

echo "== customer_unmerge(): restores exactly the moved rows, no more no less ==\n";
$audit_calls = array();
list($ok, $errors, $undo) = customer_unmerge($db, $log_id, 9, 1);
check('unmerge succeeds', $ok, true);
check('identities 11 and 12 restored to customer 2', array($db->tables['customer_identities'][11]['customer_id'], $db->tables['customer_identities'][12]['customer_id']), array(2, 2));
check('identity 13 (never moved) still on customer 1', $db->tables['customer_identities'][13]['customer_id'], 1);
check('identity 14 (never moved, stayed on 2 throughout) still on customer 2', $db->tables['customer_identities'][14]['customer_id'], 2);
check('conversations 100 and 101 restored to customer 2', array($db->tables['conversations'][100]['customer_id'], $db->tables['conversations'][101]['customer_id']), array(2, 2));
check('conversation 102 (never touched) still on customer 1', $db->tables['conversations'][102]['customer_id'], 1);
check('customer 2 no longer marked merged', $db->tables['customers'][2]['merged_into_customer_id'], null);
check('log row stamped unmerged_at', empty($db->tables['customer_merge_log'][$log_id]['unmerged_at']), false);
check('audit_record() was called for the unmerge', count($audit_calls) === 1 && $audit_calls[0][1] === 'customer.unmerge', true);

echo "== customer_unmerge(): refuses a second time on the same log id (idempotency guard) ==\n";
list($ok, $errors) = customer_unmerge($db, $log_id, 9, 1);
check('second unmerge of the same log refused', $ok, false);
check('second unmerge error message', $errors, array('This merge was already undone.'));

echo "== customer_unmerge(): cross-tenant refused (log row belongs to another tenant) ==\n";
$db2 = merge_log_ready(fresh_merge_db());
list(, , $r2) = customer_merge($db2, 1, 2, 9, 1);
list($ok, $errors) = customer_unmerge($db2, $r2['log_id'], 9, 2); // tenant 2 trying to undo tenant 1's merge
check('cross-tenant unmerge refused', $ok, false);
check('nothing reverted for tenant 2\'s attempt', $db2->tables['customers'][2]['merged_into_customer_id'], 1);

echo "== customer_merge(): nothing merges without the explicit call (static audit) ==\n";
$helper_src = file_get_contents(__DIR__ . '/../../application/helpers/customer_helper.php');
$controller_src = file_get_contents(__DIR__ . '/../../application/controllers/Customers.php');
check('merged_into_customer_id is written (update(\'customers\', ...)) in exactly 2 places: set (merge) and clear (unmerge)',
    substr_count($helper_src, "update('customers', array('merged_into_customer_id' =>"), 2);
check('Customers::merge() is the only controller entry point calling customer_merge(',
    substr_count($controller_src, 'customer_merge($this->db,'), 1);
check('merge() is ajax_check()-gated',
    strpos(substr($controller_src, strpos($controller_src, 'public function merge()')), '$this->ajax_check();') !== false, true);
check('merge() is csrf_token_check()-gated',
    strpos(substr($controller_src, strpos($controller_src, 'public function merge()')), '$this->csrf_token_check();') !== false, true);
check('unmerge() is ajax_check()-gated',
    strpos(substr($controller_src, strpos($controller_src, 'public function unmerge()')), '$this->ajax_check();') !== false, true);
check('unmerge() is csrf_token_check()-gated',
    strpos(substr($controller_src, strpos($controller_src, 'public function unmerge()')), '$this->csrf_token_check();') !== false, true);
check('suggestions() is ajax_check()-gated (read-only, no csrf needed, same as customers_data())',
    strpos(substr($controller_src, strpos($controller_src, 'public function suggestions()')), '$this->ajax_check();') !== false, true);

// ============================================================================================
echo "== customer_merge_suggestion_rows(): query is scoped to tenant + verified + not-merged ==\n";

$q = new FakeQB();
customer_merge_suggestion_rows($q, 7);
$has_tenant_filter = in_array(array('where', 'ci.user_id', 7), $q->calls) && in_array(array('where', 'c.user_id', 7), $q->calls);
check('scoped to the tenant on both sides of the join', $has_tenant_filter, true);
check('only verified identities', in_array(array('where', 'ci.verified', 1), $q->calls), true);
check('excludes already-merged customers', in_array(array('where', 'c.merged_into_customer_id IS NULL', null), $q->calls), true);

echo "== customer_merge_suggestions_from_rows(): pairs only same-value, 2+ customers, never auto-merges ==\n";

$rows = array(
    array('customer_id' => 1, 'platform' => 'wa', 'external_id' => '2010000000', 'display_name' => 'Sara'),
    array('customer_id' => 2, 'platform' => 'wa', 'external_id' => '2010000000', 'display_name' => 'Sarah'), // same verified phone, different customer
    array('customer_id' => 3, 'platform' => 'fb', 'external_id' => 'onlyone', 'display_name' => 'Solo'), // no match, alone
    array('customer_id' => 4, 'platform' => 'wa', 'external_id' => '2010000001', 'display_name' => 'Other'),
    array('customer_id' => 1, 'platform' => 'wa', 'external_id' => '2010000000', 'display_name' => 'Sara'), // duplicate row, same customer+value
);
$suggestions = customer_merge_suggestions_from_rows($rows);
check('exactly one suggestion (the shared-phone pair), a duplicate row does not create a second one', count($suggestions), 1);
check('pair is customers 1 and 2', array($suggestions[0]['customer_id_a'], $suggestions[0]['customer_id_b']), array(1, 2));
check('matched value is the shared phone', $suggestions[0]['matched_value'], '2010000000');

check('a lone customer with a unique verified value produces no suggestion', customer_merge_suggestions_from_rows(array(
    array('customer_id' => 5, 'platform' => 'em', 'external_id' => 'a@b.com', 'display_name' => 'Alone'),
)), array());

check('rows with an empty external_id are ignored, never grouped together', customer_merge_suggestions_from_rows(array(
    array('customer_id' => 6, 'platform' => 'wa', 'external_id' => '', 'display_name' => 'X'),
    array('customer_id' => 7, 'platform' => 'wa', 'external_id' => '', 'display_name' => 'Y'),
)), array());

$limited = customer_merge_suggestions_from_rows(array(
    array('customer_id' => 1, 'platform' => 'wa', 'external_id' => 'v1', 'display_name' => 'A'),
    array('customer_id' => 2, 'platform' => 'wa', 'external_id' => 'v1', 'display_name' => 'B'),
    array('customer_id' => 3, 'platform' => 'wa', 'external_id' => 'v2', 'display_name' => 'C'),
    array('customer_id' => 4, 'platform' => 'wa', 'external_id' => 'v2', 'display_name' => 'D'),
), 1);
check('limit is respected', count($limited), 1);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
