<?php
/**
 * Sequences unit tests. No CodeIgniter, no database, no network.
 *
 *   php tests/sequences/run.php
 *   docker exec -u www-data <app> php /var/www/html/tests/sequences/run.php
 *
 * sequence_helper.php's two DB-bound entry points (sequence_enroll, sequence_advance) take
 * an injectable $db (and, for sequence_advance, an injectable Automation_engine) exactly the
 * way Automation_engine itself takes an injectable gateway — see the 'db'/'engine' keys in
 * sequence_advance()'s $deps parameter. FakeDb below is a tiny in-memory stand-in for
 * CodeIgniter's query builder that implements only what sequence_helper.php actually calls
 * (from/where/order_by/limit/get/row_array/result_array, insert/insert_id, where()->update(),
 * and the two raw INSERT IGNORE statements) — including the exact UNIQUE-key-violation
 * behaviour (insert_id() === 0) that Automation_gateway::run_claim() already relies on and
 * tests/automation/run.php already proves in production.
 *
 * What THIS suite covers: enrollment idempotency, step-advancement math (next_run_at,
 * completion, pause-blocks-advance), stop-condition evaluation, a policy-denied step logged
 * as skipped_policy with nothing sent, and action dispatch (including the two new action
 * types, create_task and start_sequence) reusing Automation_gateway/Automation_engine.
 *
 * What is NOT here, deliberately: anything that needs a real database transaction, session
 * or HTTP request (e.g. the actual channel_policy_decide() 24h-window math, or CSRF/
 * entitlement gates on the Sequences controller). Those are proven end-to-end on a live
 * stack by docker/scripts/staging_sequences_tests.sh.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
define('APPPATH', dirname(__DIR__) . '/../application/');
date_default_timezone_set('UTC');
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

function log_message($level, $msg) { /* silence expected error-path logging in tests */ }

require __DIR__ . '/../../application/libraries/Automation_engine.php';
require __DIR__ . '/../../application/helpers/sequence_helper.php';

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
    public $tables = array();       // table => id => row
    public $auto = array();         // table => next id
    private $table; private $wheres = array(); private $order = null; private $lim = null;
    private $last_insert_id = 0;

    public function from($t) { $this->table = $t; $this->wheres = array(); $this->order = null; $this->lim = null; return $this; }
    public function select($c) { return $this; }
    public function where($a, $b = null) { $this->wheres[] = array($a, $b); return $this; }
    public function order_by($col, $dir = 'ASC') { $this->order = array($col, $dir); return $this; }
    public function limit($n) { $this->lim = $n; return $this; }

    /** Real CodeIgniter's query builder resets where/order/limit after EVERY query it runs
     *  (get/insert/update/delete) — a bare where()->update(...) with no from() in between
     *  relies on that reset, not on wheres accumulating across statements. */
    private function reset_builder() { $this->wheres = array(); $this->order = null; $this->lim = null; }

    public function get()
    {
        $rows = isset($this->tables[$this->table]) ? array_values($this->tables[$this->table]) : array();
        foreach ($this->wheres as $w) {
            list($k, $v) = $w;
            if ($v === null) continue;   // raw fragments (not used by sequence_helper's queries) are ignored
            $rows = array_values(array_filter($rows, function ($r) use ($k, $v) {
                return array_key_exists($k, $r) && (string) $r[$k] === (string) $v;
            }));
        }
        if ($this->order) {
            list($col, $dir) = $this->order;
            usort($rows, function ($a, $b) use ($col) { return $a[$col] <=> $b[$col]; });
            if (strtoupper($dir) === 'DESC') $rows = array_reverse($rows);
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

    public function update($table, array $data)
    {
        $this->last_insert_id = 0;
        if (isset($this->tables[$table])) {
            foreach ($this->tables[$table] as $id => &$row) {
                $match = true;
                foreach ($this->wheres as $w) {
                    list($k, $v) = $w;
                    if ($v === null) continue;
                    if (!array_key_exists($k, $row) || (string) $row[$k] !== (string) $v) { $match = false; break; }
                }
                if ($match) $row = array_merge($row, $data);
            }
            unset($row);
        }
        $this->reset_builder();
    }

    /** The two raw statements sequence_helper.php issues, with real UNIQUE-key semantics. */
    public function query($sql, array $params = array())
    {
        $this->last_insert_id = 0;
        $this->reset_builder();

        if (stripos($sql, 'INSERT IGNORE INTO sequence_enrollments') !== false) {
            list($sequence_id, $user_id, $customer_id, $subscriber_id, $platform, $subscribe_id, $channel_account_id, $enrolled_at, $next_run_at, $updated_at) = $params;
            if (!isset($this->tables['sequence_enrollments'])) { $this->tables['sequence_enrollments'] = array(); $this->auto['sequence_enrollments'] = 1; }
            foreach ($this->tables['sequence_enrollments'] as $row) {
                // UNIQUE(sequence_id, subscriber_id, platform)
                if ((string) $row['sequence_id'] === (string) $sequence_id && (string) $row['subscriber_id'] === (string) $subscriber_id && (string) $row['platform'] === (string) $platform) {
                    return; // duplicate: insert_id() stays 0, exactly like a real UNIQUE violation under INSERT IGNORE
                }
            }
            $id = $this->auto['sequence_enrollments']++;
            $this->tables['sequence_enrollments'][$id] = array(
                'id' => $id, 'sequence_id' => $sequence_id, 'user_id' => $user_id, 'customer_id' => $customer_id,
                'subscriber_id' => $subscriber_id, 'platform' => $platform, 'subscribe_id' => $subscribe_id,
                'channel_account_id' => $channel_account_id, 'current_step' => 0, 'status' => 'active',
                'enrolled_at' => $enrolled_at, 'next_run_at' => $next_run_at, 'stopped_reason' => null, 'updated_at' => $updated_at,
            );
            $this->last_insert_id = $id;
            return;
        }

        if (stripos($sql, 'INSERT IGNORE INTO sequence_step_log') !== false) {
            list($enrollment_id, $step_id, $status, $detail, $run_at) = $params;
            if (!isset($this->tables['sequence_step_log'])) { $this->tables['sequence_step_log'] = array(); $this->auto['sequence_step_log'] = 1; }
            foreach ($this->tables['sequence_step_log'] as $row) {
                // UNIQUE(enrollment_id, step_id) — the idempotency claim
                if ((string) $row['enrollment_id'] === (string) $enrollment_id && (string) $row['step_id'] === (string) $step_id) {
                    return;
                }
            }
            $id = $this->auto['sequence_step_log']++;
            $this->tables['sequence_step_log'][$id] = array(
                'id' => $id, 'enrollment_id' => $enrollment_id, 'step_id' => $step_id,
                'status' => $status, 'detail' => $detail, 'run_at' => $run_at,
            );
            $this->last_insert_id = $id;
            return;
        }

        throw new RuntimeException('FakeDb::query() does not understand: ' . $sql);
    }
}

// ---------------------------------------------------------------- fake gateway (same shape as tests/automation/run.php)

class FakeGateway
{
    public $calls = array();
    public $context = array();
    public $policy = array('allowed' => true, 'reason' => 'in_24h_window', 'window' => '24h', 'messaging_type' => 'RESPONSE', 'tag' => null);
    public $started_sequences = array();

    public function context($user_id, array $event) { return $this->context; }
    public function run_claim($rule_id, $event_id) { return 0; }        // not used by sequence_advance
    public function run_finish($run_id, $status, $detail) {}
    public function action_log($run_id, $i, $type, $status, $error, $retry) {}

    private function result($type, $args) { $this->calls[] = array($type, $args); return array(true, null, false); }
    public function add_label($u, $s, $l) { return $this->result('add_label', func_get_args()); }
    public function update_lead_score($u, $s, $d) { return $this->result('update_lead_score', func_get_args()); }
    public function create_crm_deal($u, $s, array $d) { return $this->result('create_crm_deal', func_get_args()); }
    public function move_crm_stage($u, $d, $s, $st) { return $this->result('move_crm_stage', func_get_args()); }
    public function notify_team($u, array $p) { return $this->result('notify_team', func_get_args()); }
    public function send_text($u, $p, $to, $t, $pid = '') { return $this->result('send_text', func_get_args()); }
    public function call_webhook($url, array $p) { return $this->result('call_webhook', func_get_args()); }
    public function write_google_sheet($u, array $r) { return $this->result('write_google_sheet', func_get_args()); }
    public function create_task($u, $s, $d, array $data) { return $this->result('create_task', func_get_args()); }
    public function start_sequence($u, $sequence_id, $subscriber_id)
    {
        $this->started_sequences[] = array($u, $sequence_id, $subscriber_id);
        $this->calls[] = array('start_sequence', func_get_args());
        return array(true, null, false);
    }

    public function policy_decide($platform, array $ctx) { $this->calls[] = array('policy_decide', array($platform, $ctx)); return $this->policy; }
    public function sleep_backoff($s) {}
    public function log($level, $msg) {}
    public function sent() { $n = 0; foreach ($this->calls as $c) if ($c[0] === 'send_text') $n++; return $n; }
}

function mk_engine(FakeGateway $gw) { return new Automation_engine(array('gateway' => $gw)); }

function mk_sequence(FakeDb $db, $overrides = array())
{
    $seq = array_merge(array('user_id' => 11, 'name' => 'Hotel Lead Follow-up', 'trigger_event' => 'manual',
        'enroll_conditions_json' => null, 'status' => 'active'), $overrides);
    $db->insert('sequences', $seq);
    return $db->insert_id();
}

function mk_step(FakeDb $db, $sequence_id, $position, $delay_seconds, $action_type, array $params = array(), $stop_condition = null)
{
    $db->insert('sequence_steps', array(
        'sequence_id' => $sequence_id, 'position' => $position, 'delay_seconds' => $delay_seconds,
        'action_type' => $action_type, 'action_params_json' => json_encode($params),
        'stop_condition_json' => $stop_condition ? json_encode($stop_condition) : null,
    ));
    return $db->insert_id();
}

echo "-- enrollment idempotency -------------------------------------------\n";
$db = new FakeDb();
$sid = mk_sequence($db);
mk_step($db, $sid, 0, 600, 'add_label', array('label' => 'New lead'));
mk_step($db, $sid, 1, 21600, 'send_message', array('text' => 'Reminder'));

list($ok1, $note1, $eid1) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);
is_true('first enroll succeeds', $ok1);
is_same('no note on a fresh enroll', null, $note1);
is_true('an enrollment id was returned', $eid1 > 0);
is_same('current_step starts at 0', 0, $db->tables['sequence_enrollments'][$eid1]['current_step']);
is_same('status starts active', 'active', $db->tables['sequence_enrollments'][$eid1]['status']);
is_same('next_run_at is enrolled_at + first step delay', date('Y-m-d H:i:s', 1000000000 + 600), $db->tables['sequence_enrollments'][$eid1]['next_run_at']);

list($ok2, $note2, $eid2) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000005000, $db);
is_true('re-enrolling the same subscriber is not an error', $ok2);
is_same('it reports already enrolled', 'already enrolled', $note2);
is_same('it returns the SAME enrollment id', $eid1, $eid2);
is_same('no second row was created', 1, count($db->tables['sequence_enrollments']));

list($ok3, , $eid3) = sequence_enroll(11, $sid, 502, 'fb', 'PSID502', 1, null, 1000000000, $db);
is_true('a different subscriber enrolls fine', $ok3);
is_true('and gets its own enrollment', $eid3 !== $eid1);

list($ok4, $note4) = sequence_enroll(11, 999999, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);
is_false('enrolling into an unknown sequence fails', $ok4);
is_same('with a clear reason', 'sequence not found', $note4);

$db2 = new FakeDb();
$sid2 = mk_sequence($db2);
list($ok5, $note5) = sequence_enroll(11, $sid2, 501, 'fb', 'PSID501', 1, null, 1000000000, $db2);
is_false('enrolling into a sequence with no steps fails', $ok5);
is_same('with a clear reason', 'sequence has no steps yet', $note5);

echo "-- step-advancement math ---------------------------------------------\n";
$db = new FakeDb();
$sid = mk_sequence($db);
mk_step($db, $sid, 0, 600, 'add_label', array('label' => 'Step 1'));
mk_step($db, $sid, 1, 21600, 'add_label', array('label' => 'Step 2'));
mk_step($db, $sid, 2, 259200, 'add_label', array('label' => 'Step 3'));
list(, , $eid) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);

$gw = new FakeGateway();
$engine = mk_engine($gw);
$r1 = sequence_advance($eid, 1000000700, array('db' => $db, 'engine' => $engine));
is_same('step 1 runs', 'success', $r1['status']);
is_same('current_step advances to 1', 1, $db->tables['sequence_enrollments'][$eid]['current_step']);
is_same('next_run_at is enrolled_at + step 2 delay (ABSOLUTE offset, not from now)', date('Y-m-d H:i:s', 1000000000 + 21600), $db->tables['sequence_enrollments'][$eid]['next_run_at']);
is_same('enrollment is still active', 'active', $db->tables['sequence_enrollments'][$eid]['status']);

$r2 = sequence_advance($eid, 1000021600, array('db' => $db, 'engine' => $engine));
is_same('step 2 runs', 'success', $r2['status']);
is_same('current_step advances to 2', 2, $db->tables['sequence_enrollments'][$eid]['current_step']);
is_same('next_run_at is enrolled_at + step 3 delay', date('Y-m-d H:i:s', 1000000000 + 259200), $db->tables['sequence_enrollments'][$eid]['next_run_at']);

$r3 = sequence_advance($eid, 1000259200, array('db' => $db, 'engine' => $engine));
is_same('step 3 (the last one) runs', 'success', $r3['status']);
is_same('the enrollment completes', 'completed', $db->tables['sequence_enrollments'][$eid]['status']);
is_true('next_run_at is cleared on completion', $db->tables['sequence_enrollments'][$eid]['next_run_at'] === null);

$r4 = sequence_advance($eid, 1000300000, array('db' => $db, 'engine' => $engine));
is_same('advancing a completed enrollment is a no-op', 'skipped', $r4['status']);
is_same('the gateway saw exactly 3 actions (one per step, never re-run)', 3, count($gw->calls));

echo "-- re-running the worker does not re-fire a completed step -----------\n";
$db = new FakeDb();
$sid = mk_sequence($db);
mk_step($db, $sid, 0, 0, 'add_label', array('label' => 'Only step'));
list(, , $eid) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);
$gw = new FakeGateway();
$engine = mk_engine($gw);
$first = sequence_advance($eid, 1000000000, array('db' => $db, 'engine' => $engine));
is_same('first run succeeds', 'success', $first['status']);
is_same('one action ran', 1, count($gw->calls));
// simulate a replayed/duplicate worker tick against the SAME (now-completed) enrollment
$replay = sequence_advance($eid, 1000000500, array('db' => $db, 'engine' => $engine));
is_same('replay after completion changes nothing', 'skipped', $replay['status']);
is_same('still exactly one action ever ran', 1, count($gw->calls));

echo "-- pausing a sequence blocks advancing, not the enrollment's position -\n";
$db = new FakeDb();
$sid = mk_sequence($db, array('status' => 'paused'));
mk_step($db, $sid, 0, 0, 'add_label', array('label' => 'x'));
mk_step($db, $sid, 1, 600, 'add_label', array('label' => 'y'));
list(, , $eid) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);
$gw = new FakeGateway();
$r = sequence_advance($eid, 1000000000, array('db' => $db, 'engine' => mk_engine($gw)));
is_same('advancing a paused sequence is skipped', 'skipped', $r['status']);
is_same('the step did NOT run', 0, count($gw->calls));
is_same('current_step is untouched', 0, $db->tables['sequence_enrollments'][$eid]['current_step']);
is_same('next_run_at is left as-is (resumes on its own once un-paused)', date('Y-m-d H:i:s', 1000000000), $db->tables['sequence_enrollments'][$eid]['next_run_at']);

echo "-- stop condition ------------------------------------------------------\n";
$db = new FakeDb();
$sid = mk_sequence($db);
mk_step($db, $sid, 0, 0, 'add_label', array('label' => 'x'), array(array('field' => 'lead_score', 'op' => 'gte', 'value' => 90)));
mk_step($db, $sid, 1, 600, 'add_label', array('label' => 'never reached'));
list(, , $eid) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);

$gw = new FakeGateway();
$gw->context = array('promote' => array('lead_score' => 95));
$r = sequence_advance($eid, 1000000000, array('db' => $db, 'engine' => mk_engine($gw)));
is_same('a true stop condition halts before the action runs', 'skipped_stop_condition', $r['status']);
is_same('the action was never called', 0, count($gw->calls));
is_same('the enrollment is stopped', 'stopped', $db->tables['sequence_enrollments'][$eid]['status']);
is_true('next_run_at is cleared', $db->tables['sequence_enrollments'][$eid]['next_run_at'] === null);
is_true('a stopped_reason is recorded (brief §51: no silent failure)', !empty($db->tables['sequence_enrollments'][$eid]['stopped_reason']));

$db = new FakeDb();
$sid = mk_sequence($db);
mk_step($db, $sid, 0, 0, 'add_label', array('label' => 'x'), array(array('field' => 'lead_score', 'op' => 'gte', 'value' => 90)));
list(, , $eid) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);
$gw = new FakeGateway();
$gw->context = array('promote' => array('lead_score' => 10));
$r = sequence_advance($eid, 1000000000, array('db' => $db, 'engine' => mk_engine($gw)));
is_same('a false stop condition lets the step run normally', 'success', $r['status']);
is_same('the action ran', 1, count($gw->calls));

echo "-- 'replied_since_enrollment' convenience field ------------------------\n";
$engine = mk_engine(new FakeGateway());
$enr = array('enrolled_at' => '2026-09-24 10:00:00');
$event = array('id' => 0, 'user_id' => 11, 'event_type' => 'sequence.step', 'subject_type' => 'subscriber', 'subject_id' => 501, 'payload' => '{}', 'correlation_id' => null);
$gw2 = new FakeGateway();
$gw2->context = array('subscriber' => array('last_subscriber_interaction_time' => '2026-09-24 11:00:00'));
$data_after = sequence_evaluation_data(mk_engine($gw2), $enr, $event);
is_true('a reply after enrollment sets the flag true', $data_after['replied_since_enrollment']);
$gw3 = new FakeGateway();
$gw3->context = array('subscriber' => array('last_subscriber_interaction_time' => '2026-09-24 09:00:00'));
$data_before = sequence_evaluation_data(mk_engine($gw3), $enr, $event);
is_false('a reply before enrollment leaves the flag false', $data_before['replied_since_enrollment']);
$gw4 = new FakeGateway();
$data_none = sequence_evaluation_data(mk_engine($gw4), $enr, $event);
is_false('no subscriber context at all is false, not an error', $data_none['replied_since_enrollment']);

echo "-- policy-denied step is logged, nothing sent --------------------------\n";
$db = new FakeDb();
$sid = mk_sequence($db);
mk_step($db, $sid, 0, 0, 'send_message', array('text' => 'Hello {{full_name}}'));
mk_step($db, $sid, 1, 600, 'add_label', array('label' => 'after'));
list(, , $eid) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);

$gw = new FakeGateway();
$gw->policy = array('allowed' => false, 'reason' => 'automation_outside_24h_window', 'window' => '24h+', 'messaging_type' => null, 'tag' => null);
$r = sequence_advance($eid, 1000000000, array('db' => $db, 'engine' => mk_engine($gw)));
is_same('the send is logged as skipped_policy', 'skipped_policy', $r['status']);
is_same('the policy reason is the detail', 'automation_outside_24h_window', $r['detail']);
is_same('NOTHING was sent', 0, $gw->sent());
is_same('sequence_step_log recorded the denial, not silence', 'skipped_policy', $db->tables['sequence_step_log'][1]['status']);
is_same('the sequence still advances to the next step (a denial does not abort it)', 1, $db->tables['sequence_enrollments'][$eid]['current_step']);

$policy_ctx = null;
foreach ($gw->calls as $c) if ($c[0] === 'policy_decide') $policy_ctx = $c[1][1];
is_same('the sequence asks the SAME channel policy layer, as automation', 'automation', $policy_ctx['sender']);

echo "-- action dispatch reuses Automation_gateway/Automation_engine --------\n";
$db = new FakeDb();
$sid = mk_sequence($db);
mk_step($db, $sid, 0, 0, 'add_label', array('label' => 'Hot lead'));
mk_step($db, $sid, 1, 600, 'update_lead_score', array('delta' => 5));
mk_step($db, $sid, 2, 1200, 'create_task', array('subject' => 'Call {{full_name}}', 'due_in_minutes' => 30));
mk_step($db, $sid, 3, 1800, 'start_sequence', array('sequence_id' => 42));
list(, , $eid) = sequence_enroll(11, $sid, 501, 'fb', 'PSID501', 1, null, 1000000000, $db);

$gw = new FakeGateway();
$engine = mk_engine($gw);
sequence_advance($eid, 1000000000, array('db' => $db, 'engine' => $engine));
sequence_advance($eid, 1000000600, array('db' => $db, 'engine' => $engine));
sequence_advance($eid, 1000001200, array('db' => $db, 'engine' => $engine));
sequence_advance($eid, 1000001800, array('db' => $db, 'engine' => $engine));
$types = array_map(function ($c) { return $c[0]; }, $gw->calls);
is_same('every step dispatched through the same gateway calls an Automation Rule would use', array('add_label', 'update_lead_score', 'create_task', 'start_sequence'), $types);
is_same('create_task got the templated subject', 'Call ', $gw->calls[2][1][3]['subject']);   // {{full_name}} renders empty: no subscriber context in this fixture
is_same('start_sequence composes an Automation Rule action -> Sequence enrollment', array(11, 42, 501), $gw->started_sequences[0]);
is_same('the enrollment completed after its last step', 'completed', $db->tables['sequence_enrollments'][$eid]['status']);

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL === 0 && $PASS > 0 ? 0 : 1);
