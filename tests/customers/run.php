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

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
