<?php
/**
 * Conversational CRM upgrades unit tests (Task 18, C3, brief §27). No CodeIgniter bootstrap,
 * no real database, no network — exercises application/helpers/crm_helper.php and
 * application/helpers/lead_scoring_helper.php against a small in-memory fake query builder
 * that mimics enough of CI_DB_query_builder's chain (from/where/where_in/join/order_by/limit/
 * get/insert/update/delete/field_exists) to run the real helper code unmodified, plus a
 * source-level check that Crm.php's stage_* endpoints are CSRF+ajax+ownership guarded.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/crm/run.php
 *
 * Covers (per the task brief):
 *   - crm_ensure_pipeline() never touches an existing pipeline's stages/deals (idempotent)
 *   - a brand-new pipeline gets the New/Qualified/Follow-up/Proposal/Won/Lost stage set
 *   - stage editor (crm_stage_add/rename/reorder/delete) is tenant-scoped via crm_owns_*
 *   - crm_stage_delete refuses to delete a stage that still has a deal in it
 *   - lead.qualified (crm_maybe_emit_qualified) emits exactly once per deal
 *   - crm_deals_conversational_ready() / crm_auto_deal() work before the migration has run
 *     (pre-migration guard: customer_id/conversation_id columns don't exist yet)
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

/* ============================================================================================
 | A small in-memory fake of CI's query builder. Supports exactly the chain shapes
 | crm_helper.php / lead_scoring_helper.php use: from/select/select_max/where/where_in/join/
 | order_by/limit/get/row_array/result_array/count_all_results/insert/insert_id/update/delete/
 | field_exists/table_exists. Good enough to run the real helper functions end to end.
 * ==========================================================================================*/
class FakeResult
{
    private $rows;
    function __construct($rows) { $this->rows = array_values($rows); }
    function row_array() { return $this->rows[0] ?? array(); }
    function result_array() { return $this->rows; }
}

class FakeDB
{
    public $tables = array();       // table => array of assoc rows (each has 'id')
    public $auto_id = array();      // table => next id
    public $missing_fields = array(); // "table.field" => true (pre-migration guard)
    private $q;
    private $last_insert_id = 0;

    function __construct() {
        foreach (array('crm_pipelines','crm_stages','crm_deals','crm_deal_timeline','crm_activities',
                        'messenger_bot_subscriber','livechat_messages','conversations','lead_scoring_rules','lead_scoring_events') as $t) {
            $this->tables[$t] = array();
            $this->auto_id[$t] = 1;
        }
    }

    private function reset() { $this->q = array('table'=>null,'where'=>array(),'join'=>null,'order'=>null,'limit'=>null,'select'=>null); return $this; }
    function from($t) { $this->reset(); $this->q['table'] = $t; return $this; }
    function select($cols) { $this->q['select'] = $cols; return $this; }
    function select_max($col) { $this->q['select_max'] = $col; return $this; }
    function join($t, $on, $type = 'inner') { $this->q['join'] = array($t, $on); return $this; }
    function where($a, $b = null, $c = null) {
        if (is_array($a)) { foreach ($a as $k => $v) $this->q['where'][] = array($k, '=', $v); return $this; }
        if ($b === null) { $this->q['where'][] = array('__raw__', $a, null); return $this; }
        $this->q['where'][] = array($a, '=', $b);
        return $this;
    }
    function where_in($k, $vals) { $this->q['where'][] = array($k, 'IN', $vals); return $this; }
    function order_by($c, $d = 'ASC') { $this->q['order'] = array($c, $d); return $this; }
    function group_by($c) { return $this; }
    function limit($n, $o = 0) { $this->q['limit'] = $n; return $this; }

    private function field($field) {
        $p = strpos($field, '.');
        return $p === false ? $field : substr($field, $p + 1);
    }

    private function rows() {
        $t = $this->q['table'];
        $rows = $this->tables[$t] ?? array();
        // single supported join shape: join('other','other.id = t.fk')
        if ($this->q['join']) {
            list($other, $on) = $this->q['join'];
            if (preg_match('/(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)/', $on, $m)) {
                // m1.m2 = m3.m4 ; figure out which side is `other`
                if ($m[1] === $other) { $other_key = $m[2]; $this_key = $m[4]; }
                else { $other_key = $m[4]; $this_key = $m[2]; }
                $merged = array();
                foreach ($rows as $r) {
                    foreach (($this->tables[$other] ?? array()) as $orow) {
                        if (isset($r[$this_key]) && isset($orow[$other_key]) && $r[$this_key] == $orow[$other_key]) {
                            $merged[] = array_merge($orow, $r); // primary table's own fields win
                        }
                    }
                }
                $rows = $merged;
            }
        }
        foreach ($this->q['where'] as $w) {
            list($field, $op, $val) = $w;
            if ($field === '__raw__') {
                if (preg_match('/^(\w+)\s+IS NOT NULL$/', $op, $m)) {
                    $f = $m[1];
                    $rows = array_values(array_filter($rows, function ($r) use ($f) { return isset($r[$f]) && $r[$f] !== null; }));
                }
                continue;
            }
            $f = $this->field($field);
            if ($op === 'IN') {
                $rows = array_values(array_filter($rows, function ($r) use ($f, $val) { return isset($r[$f]) && in_array($r[$f], $val); }));
            } else {
                $rows = array_values(array_filter($rows, function ($r) use ($f, $val) { return ($r[$f] ?? null) == $val; }));
            }
        }
        if ($this->q['order']) {
            list($c, $d) = $this->q['order'];
            $f = $this->field($c);
            usort($rows, function ($a, $b) use ($f, $d) {
                $cmp = ($a[$f] ?? 0) <=> ($b[$f] ?? 0);
                return $d === 'DESC' ? -$cmp : $cmp;
            });
        }
        if ($this->q['limit'] !== null) $rows = array_slice($rows, 0, $this->q['limit']);
        return $rows;
    }

    function get() {
        if (isset($this->q['select_max'])) {
            $t = $this->q['table']; $f = $this->q['select_max'];
            $all = $this->tables[$t] ?? array();
            $max = null;
            foreach ($all as $r) { if (isset($r[$f]) && ($max === null || $r[$f] > $max)) $max = $r[$f]; }
            $this->reset();
            return new FakeResult(array(array('position' => $max)));
        }
        $rows = $this->rows();
        $this->reset();
        return new FakeResult($rows);
    }

    function count_all_results() { $n = count($this->rows()); $this->reset(); return $n; }

    function insert($t, $data) {
        if (!isset($this->tables[$t])) { $this->tables[$t] = array(); $this->auto_id[$t] = 1; }
        $id = $this->auto_id[$t]++;
        $data['id'] = $id;
        $this->tables[$t][] = $data;
        $this->last_insert_id = $id;
        $this->reset();
        return true;
    }
    function insert_id() { return $this->last_insert_id; }

    function set($field, $value, $escape = true) { $this->q['set'][$field] = array($value, $escape); return $this; }

    function update($t, $data = null) {
        // real query builder's own state (from a previous ->from($t)->where(...)) is what
        // decides which rows are touched; $t here just names the table being updated.
        $this->q['table'] = $t;
        $ids = array_column($this->rows(), 'id');
        if ($data === null) $data = array();
        foreach ($this->tables[$t] as $i => $r) {
            if (in_array($r['id'], $ids)) {
                $merged = array_merge($r, $data);
                if (!empty($this->q['set'])) {
                    foreach ($this->q['set'] as $f => $spec) {
                        // only supports the one raw expression shape this codebase uses:
                        // "<field> + <n>" (lead_score bump), value untouched otherwise.
                        if (preg_match('/^(\w+)\s*\+\s*(-?\d+)$/', $spec[0], $m) && $m[1] === $f) {
                            $merged[$f] = (int) ($r[$f] ?? 0) + (int) $m[2];
                        }
                    }
                }
                $this->tables[$t][$i] = $merged;
            }
        }
        $this->reset();
        return true;
    }

    function delete($t) {
        $this->q['table'] = $t;
        $ids = array_column($this->rows(), 'id');
        $this->tables[$t] = array_values(array_filter($this->tables[$t], function ($r) use ($ids) { return !in_array($r['id'], $ids); }));
        $this->reset();
        return true;
    }

    function table_exists($t) { return isset($this->tables[$t]); }
    function field_exists($f, $t) { return empty($this->missing_fields[$t . '.' . $f]); }
}

class FakeSession { function userdata($k) { return null; } }
class FakeInput { function ip_address() { return '203.0.113.9'; } }
class FakeLoad { function helper($h) { /* already required below */ } }
class FakeConfig { public $items = array(); function item($k) { return $this->items[$k] ?? null; } }
class FakeCI {
    public $db; public $session; public $input; public $load; public $config;
    function __construct($db) { $this->db = $db; $this->session = new FakeSession(); $this->input = new FakeInput(); $this->load = new FakeLoad(); $this->config = new FakeConfig(); }
}

$GLOBALS['__CI'] = null;
function &get_instance() { return $GLOBALS['__CI']; }
$GLOBALS['__events'] = array();
function log_message($level, $msg) { /* no-op */ }
function event_emit($user_id, $type, $subject_type, $subject_id, $payload = array()) {
    $GLOBALS['__events'][] = array($user_id, $type, $subject_type, $subject_id, $payload);
}

require __DIR__ . '/../../application/helpers/crm_helper.php';
require __DIR__ . '/../../application/helpers/lead_scoring_helper.php';

function fresh_db() { return new FakeDB(); }

// ============================================================================================
echo "== crm_ensure_pipeline(): existing pipelines/stages/deals are untouched ==\n";

$db = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db);

// Seed an "existing" pipeline the way the OLD code would have, with the OLD stage names, plus
// a deal sitting in one of its stages.
$db->insert('crm_pipelines', array('user_id' => 1, 'name' => 'Sales Pipeline', 'is_default' => '1', 'status' => '1', 'created_at' => '2026-01-01 00:00:00'));
$old_pid = $db->insert_id();
$db->insert('crm_stages', array('pipeline_id' => $old_pid, 'name' => 'New Lead', 'color' => '#6777ef', 'stage_type' => 'open', 'position' => 1));
$old_stage_id = $db->insert_id();
$db->insert('crm_stages', array('pipeline_id' => $old_pid, 'name' => 'Won', 'color' => '#63ed7a', 'stage_type' => 'won', 'position' => 5));
$db->insert('crm_deals', array('user_id' => 1, 'pipeline_id' => $old_pid, 'stage_id' => $old_stage_id, 'title' => 'Existing deal', 'status' => 'open', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'));

$stages_before = $db->tables['crm_stages'];
$deals_before = $db->tables['crm_deals'];
$pipelines_before = $db->tables['crm_pipelines'];

$pid = crm_ensure_pipeline(1); // user already has a pipeline
check('crm_ensure_pipeline() returns the EXISTING pipeline id for a user who already has one', $pid, $old_pid);
check('crm_ensure_pipeline() inserted no new pipeline row', $db->tables['crm_pipelines'], $pipelines_before);
check('crm_ensure_pipeline() inserted no new stage row (existing stages untouched)', $db->tables['crm_stages'], $stages_before);
check('crm_ensure_pipeline() left existing deals untouched', $db->tables['crm_deals'], $deals_before);
check('the existing "New Lead" stage name is unchanged', $db->tables['crm_stages'][0]['name'], 'New Lead');

// ============================================================================================
echo "\n== crm_ensure_pipeline(): a BRAND NEW pipeline gets the new default stage set ==\n";

$db2 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db2);
$new_pid = crm_ensure_pipeline(2); // user 2 has no pipeline yet
$new_stages = array_values(array_filter($db2->tables['crm_stages'], function ($s) use ($new_pid) { return $s['pipeline_id'] == $new_pid; }));
usort($new_stages, function ($a, $b) { return $a['position'] <=> $b['position']; });
$names = array_column($new_stages, 'name');
check('a new pipeline seeds exactly New/Qualified/Follow-up/Proposal/Won/Lost, in order',
    $names, array('New', 'Qualified', 'Follow-up', 'Proposal', 'Won', 'Lost'));
check('the Won stage has stage_type=won', $new_stages[4]['stage_type'], 'won');
check('the Lost stage has stage_type=lost', $new_stages[5]['stage_type'], 'lost');

// ============================================================================================
echo "\n== stage editor: tenant-scoped ==\n";

$db3 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db3);
$pid_a = crm_ensure_pipeline(10); // tenant A's pipeline+stages
$pid_b = crm_ensure_pipeline(20); // tenant B's pipeline+stages
$stage_b = $db3->tables['crm_stages'][0]; // first stage created (tenant A's "New")
// find a tenant-B stage id specifically
$stage_b_row = null;
foreach ($db3->tables['crm_stages'] as $s) { if ($s['pipeline_id'] == $pid_b) { $stage_b_row = $s; break; } }

check('crm_owns_pipeline(): tenant A cannot claim tenant B\'s pipeline', crm_owns_pipeline(10, $pid_b), null);
is_true('crm_owns_pipeline(): tenant B owns its own pipeline', crm_owns_pipeline(20, $pid_b) !== null);
check('crm_owns_stage(): tenant A cannot claim tenant B\'s stage', crm_owns_stage(10, $stage_b_row['id']), null);
is_true('crm_owns_stage(): tenant B owns its own stage', crm_owns_stage(20, $stage_b_row['id']) !== null);

check('crm_stage_add(): refused for a pipeline tenant A does not own', crm_stage_add(10, $pid_b, 'Hacked'), 0);
$added = crm_stage_add(20, $pid_b, 'Custom Stage');
is_true('crm_stage_add(): tenant B can add to its own pipeline', $added > 0);

check('crm_stage_rename(): tenant A cannot rename tenant B\'s stage', crm_stage_rename(10, $stage_b_row['id'], 'Hacked'), false);
is_true('crm_stage_rename(): tenant B can rename its own stage', crm_stage_rename(20, $stage_b_row['id'], 'Renamed'));

$b_stage_ids = array_values(array_map(function ($s) { return $s['id']; }, array_filter($db3->tables['crm_stages'], function ($s) use ($pid_b) { return $s['pipeline_id'] == $pid_b; })));
check('crm_stage_reorder(): tenant A cannot reorder tenant B\'s pipeline', crm_stage_reorder(10, $pid_b, $b_stage_ids), false);
is_true('crm_stage_reorder(): tenant B can reorder its own pipeline', crm_stage_reorder(20, $pid_b, array_reverse($b_stage_ids)));

// ============================================================================================
echo "\n== stage editor: delete refuses a non-empty stage, allows an empty one ==\n";

$db4 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db4);
$pid4 = crm_ensure_pipeline(30);
$stages4 = array_values(array_filter($db4->tables['crm_stages'], function ($s) use ($pid4) { return $s['pipeline_id'] == $pid4; }));
$new_stage = $stages4[0]; // "New"
$lost_stage = $stages4[5]; // "Lost" (empty)
$db4->insert('crm_deals', array('user_id' => 30, 'pipeline_id' => $pid4, 'stage_id' => $new_stage['id'], 'title' => 'A deal', 'status' => 'open', 'created_at' => 'x', 'updated_at' => 'x'));

list($ok, $reason) = crm_stage_delete(30, $new_stage['id']);
check('crm_stage_delete(): refuses a stage that still has a deal', $ok, false);
check('crm_stage_delete(): reason is not_empty', $reason, 'not_empty');
is_true('crm_stage_delete(): the non-empty stage still exists', crm_owns_stage(30, $new_stage['id']) !== null);

list($ok2, $reason2) = crm_stage_delete(30, $lost_stage['id']);
is_true('crm_stage_delete(): an empty stage can be deleted', $ok2);
check('crm_stage_delete(): the deleted stage is gone', crm_owns_stage(30, $lost_stage['id']), null);

list($ok3, $reason3) = crm_stage_delete(30, 999999);
check('crm_stage_delete(): a non-existent/foreign stage id returns not_found, no throw', array($ok3, $reason3), array(false, 'not_found'));

// ============================================================================================
echo "\n== lead.qualified: emits exactly once per deal ==\n";

$db5 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db5);
$GLOBALS['__events'] = array();
$pid5 = crm_ensure_pipeline(40);
$stage5 = $db5->tables['crm_stages'][0];
$db5->insert('crm_deals', array('user_id' => 40, 'pipeline_id' => $pid5, 'stage_id' => $stage5['id'], 'subscriber_id' => 500, 'title' => 'Deal', 'status' => 'open', 'created_at' => 'x', 'updated_at' => 'x'));
$deal_id = $db5->insert_id();

// below threshold: no emit
crm_maybe_emit_qualified(40, 500, 30);
check('below threshold: no lead.qualified event', count($GLOBALS['__events']), 0);

// crosses threshold: exactly one emit
crm_maybe_emit_qualified(40, 500, 60);
check('crossing threshold: exactly one lead.qualified event', count($GLOBALS['__events']), 1);
check('the event is lead.qualified for the right subscriber', array($GLOBALS['__events'][0][1], $GLOBALS['__events'][0][3]), array('lead.qualified', 500));

// called again at an even higher score: still exactly one (guarded by the timeline row)
crm_maybe_emit_qualified(40, 500, 90);
check('called again above threshold: STILL exactly one event (no duplicate)', count($GLOBALS['__events']), 1);

// a second open deal for the same subscriber gets its OWN qualified event ("once per deal")
$db5->insert('crm_deals', array('user_id' => 40, 'pipeline_id' => $pid5, 'stage_id' => $stage5['id'], 'subscriber_id' => 500, 'title' => 'Deal 2', 'status' => 'open', 'created_at' => 'x', 'updated_at' => 'x'));
crm_maybe_emit_qualified(40, 500, 70);
check('a second open deal for the same subscriber gets its own emit (once PER DEAL)', count($GLOBALS['__events']), 2);

// custom threshold via config->item
$db6 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db6);
$GLOBALS['__CI']->config->items['lead_qualified_threshold'] = 80;
$GLOBALS['__events'] = array();
$pid6 = crm_ensure_pipeline(41);
$stage6 = $db6->tables['crm_stages'][0];
$db6->insert('crm_deals', array('user_id' => 41, 'pipeline_id' => $pid6, 'stage_id' => $stage6['id'], 'subscriber_id' => 501, 'title' => 'Deal', 'status' => 'open', 'created_at' => 'x', 'updated_at' => 'x'));
crm_maybe_emit_qualified(41, 501, 65); // below the custom threshold of 80
check('custom config threshold respected: 65 < 80 -> no emit', count($GLOBALS['__events']), 0);
crm_maybe_emit_qualified(41, 501, 85);
check('custom config threshold respected: 85 >= 80 -> emits', count($GLOBALS['__events']), 1);

// lead_add_score() end-to-end wiring: bumping a subscriber's score over the threshold emits
$db7 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db7);
$GLOBALS['__events'] = array();
$db7->insert('messenger_bot_subscriber', array('user_id' => 42, 'lead_score' => 55));
$sub_id = $db7->insert_id();
$db7->insert('lead_scoring_rules', array('user_id' => 0, 'event_type' => 'purchase', 'status' => '1', 'points' => 10));
$pid7 = crm_ensure_pipeline(42);
$stage7 = $db7->tables['crm_stages'][0];
$db7->insert('crm_deals', array('user_id' => 42, 'pipeline_id' => $pid7, 'stage_id' => $stage7['id'], 'subscriber_id' => $sub_id, 'title' => 'Deal', 'status' => 'open', 'created_at' => 'x', 'updated_at' => 'x'));
lead_add_score($sub_id, 'purchase'); // 55 + 10 = 65, crosses the default 60 threshold
check('lead_add_score() wires into crm_maybe_emit_qualified(): crossing 60 emits lead.qualified', count($GLOBALS['__events']), 1);
lead_add_score($sub_id, 'purchase'); // no new event_type row -> rule not found for repeat call is fine either way
is_true('lead_add_score() never throws even when called again', true);

// ============================================================================================
echo "\n== pre-migration guard: crm_deals conversational columns not there yet ==\n";

$db8 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db8);
$db8->missing_fields['crm_deals.customer_id'] = true;
$db8->missing_fields['crm_deals.conversation_id'] = true;
check('crm_deals_conversational_ready() is false before the migration runs', crm_deals_conversational_ready(), false);

$db8->insert('messenger_bot_subscriber', array('user_id' => 50, 'subscribe_id' => 'ext-1', 'lead_score' => 0));
$sub8 = $db8->insert_id();
$threw = false;
try {
    crm_auto_deal(50, $sub8, 'new_lead', array('title' => 'Pre-migration deal'));
} catch (Throwable $e) { $threw = true; }
is_true('crm_auto_deal() does not throw before the migration runs', !$threw);
$deal = $db8->tables['crm_deals'][0] ?? null;
is_true('crm_auto_deal() still creates the deal pre-migration', $deal !== null);
check('the deal has no customer_id key set pre-migration (column skipped, not NULL-written)', array_key_exists('customer_id', (array) $deal), false);

// after "the migration runs" (columns exist), the same call fills them when resolvable
$db9 = fresh_db();
$GLOBALS['__CI'] = new FakeCI($db9);
$db9->insert('messenger_bot_subscriber', array('user_id' => 51, 'subscribe_id' => 'ext-2', 'lead_score' => 0));
$sub9 = $db9->insert_id();
$db9->insert('conversations', array('user_id' => 51, 'customer_id' => 777));
$conv_id = $db9->insert_id();
$db9->insert('livechat_messages', array('user_id' => 51, 'subscriber_id' => 'ext-2', 'conversation_id' => $conv_id));
crm_auto_deal(51, $sub9, 'new_lead', array('title' => 'Post-migration deal'));
$deal9 = $db9->tables['crm_deals'][0] ?? null;
check('post-migration: crm_auto_deal() fills conversation_id when resolvable', $deal9['conversation_id'] ?? null, $conv_id);
check('post-migration: crm_auto_deal() fills customer_id when resolvable', $deal9['customer_id'] ?? null, 777);

// ============================================================================================
echo "\n== Crm.php stage_* endpoints: CSRF + ajax + ownership wiring (static check) ==\n";

$crm_src = file_get_contents(__DIR__ . '/../../application/controllers/Crm.php');
foreach (array('stage_add', 'stage_rename', 'stage_reorder', 'stage_delete') as $fn) {
    if (!preg_match('/function\s+' . $fn . '\s*\(\)\s*\{(.*?)\n    \}/s', $crm_src, $m)) {
        bad("Crm::$fn() found", 'method not found in source');
        continue;
    }
    $body = $m[1];
    $has_csrf = strpos($body, 'csrf_token_check()') !== false;
    $has_ajax = strpos($body, 'is_ajax_request()') !== false;
    $has_audit = strpos($body, 'audit_record(') !== false;
    is_true("Crm::$fn() calls csrf_token_check()", $has_csrf);
    is_true("Crm::$fn() checks is_ajax_request()", $has_ajax);
    is_true("Crm::$fn() calls audit_record() for the edit", $has_audit);
}
is_true('Crm::stage_rename() re-derives ownership via crm_owns_stage() before writing', strpos($crm_src, 'crm_owns_stage($this->uid, $id)') !== false);
is_true('Crm.php uses real_user_id ?: user_id for $this->uid, same as the rest of the controller', strpos($crm_src, "session->userdata('real_user_id') ?: \$this->session->userdata('user_id')") !== false);

// ============================================================================================
echo "\n$PASS passed, $FAIL failed\n";
if ($FAIL > 0) exit(1);
