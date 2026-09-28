<?php
/**
 * Automation Engine unit tests. No CodeIgniter, no database, no network.
 *
 *   php tests/automation/run.php
 *   docker exec -u www-data <app> php /var/www/html/tests/automation/run.php
 *
 * The engine takes its side effects through a gateway object, so everything below runs
 * against FakeGateway: condition matrix, action dispatch, the channel-policy denial path,
 * idempotency per (rule, event), tenant isolation and the retry/backoff loop.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
define('APPPATH', dirname(__DIR__) . '/../application/');
date_default_timezone_set('UTC');
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

require __DIR__ . '/../../application/libraries/Automation_engine.php';

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

// ---------------------------------------------------------------- fake gateway

class FakeGateway
{
    public $calls = array();            // every side effect, in order
    public $rules = array();            // returned by active_rules()
    public $context = array();          // returned by context()
    public $claimed = array();          // rule_id:event_id => run_id, mimics the UNIQUE index
    public $runs = array();             // run_id => array(status, detail)
    public $actions = array();          // action log rows
    public $policy = array('allowed' => true, 'reason' => 'in_24h_window', 'window' => '24h', 'messaging_type' => 'RESPONSE', 'tag' => null);
    public $fail_queue = array();       // action type => list of array(ok, err, retryable) to return in order
    public $slept = array();
    public $logs = array();
    private $next_run = 100;

    public function active_rules($user_id, $event_type)
    {
        $out = array();
        foreach ($this->rules as $r) {
            if ((int) $r['user_id'] === (int) $user_id && $r['trigger_event'] === $event_type && $r['status'] === 'active') $out[] = $r;
        }
        return $out;
    }
    public function context($user_id, array $event) { return $this->context; }

    public function run_claim($rule_id, $event_id)
    {
        $k = $rule_id . ':' . $event_id;
        if (isset($this->claimed[$k])) return 0;            // INSERT IGNORE found a duplicate
        $id = $this->next_run++;
        $this->claimed[$k] = $id;
        $this->runs[$id] = array('status' => 'skipped', 'detail' => 'running');
        return $id;
    }
    public function run_finish($run_id, $status, $detail) { $this->runs[$run_id] = array('status' => $status, 'detail' => $detail); }
    public function action_log($run_id, $i, $type, $status, $error, $retry) {
        $this->actions[] = array('run_id' => $run_id, 'index' => $i, 'type' => $type, 'status' => $status, 'error' => $error, 'retry_count' => $retry);
    }

    /** Returns the queued result for this action type, or success. */
    private function result($type, $args)
    {
        $this->calls[] = array($type, $args);
        if (!empty($this->fail_queue[$type])) return array_shift($this->fail_queue[$type]);
        return array(true, null, false);
    }
    public function add_label($u, $s, $l) { return $this->result('add_label', func_get_args()); }
    public function update_lead_score($u, $s, $d) { return $this->result('update_lead_score', func_get_args()); }
    public function create_crm_deal($u, $s, array $d) { return $this->result('create_crm_deal', func_get_args()); }
    public function move_crm_stage($u, $d, $s, $st) { return $this->result('move_crm_stage', func_get_args()); }
    public function notify_team($u, array $p) { return $this->result('notify_team', func_get_args()); }
    public function send_text($u, $p, $to, $t, $pid = '') { return $this->result('send_text', func_get_args()); }
    public function call_webhook($url, array $p) { return $this->result('call_webhook', func_get_args()); }
    public function write_google_sheet($u, array $r) { return $this->result('write_google_sheet', func_get_args()); }
    public function assign_agent($u, $s, $a) { return $this->result('assign_agent', func_get_args()); }
    public function update_field($u, $t, $id, $f, $v) { return $this->result('update_field', func_get_args()); }
    public function notify_customer_email($u, $email, $subj, $text) { return $this->result('notify_customer_email', func_get_args()); }

    public function policy_decide($platform, array $ctx) { $this->calls[] = array('policy_decide', array($platform, $ctx)); return $this->policy; }
    public function sleep_backoff($s) { $this->slept[] = $s; }
    public function log($level, $msg) { $this->logs[] = array($level, $msg); }

    public function sent() { $n = 0; foreach ($this->calls as $c) if ($c[0] === 'send_text') $n++; return $n; }
    public function types() { $t = array(); foreach ($this->calls as $c) $t[] = $c[0]; return $t; }
}

function mk_engine(FakeGateway $gw) { return new Automation_engine(array('gateway' => $gw)); }
function mk_event($overrides = array())
{
    return array_merge(array(
        'id' => 7, 'user_id' => 11, 'event_type' => 'lead.created', 'subject_type' => 'deal',
        'subject_id' => '55', 'payload' => json_encode(array('lead_score' => 75, 'platform' => 'fb', 'subscriber_id' => 21, 'subscribe_id' => 'PSID9', 'full_name' => 'Nada', 'text' => 'I want the Double Garden room')),
        'correlation_id' => null, 'created_at' => '2026-09-24 10:00:00',
    ), $overrides);
}
function mk_rule($conditions, $actions, $overrides = array())
{
    return array_merge(array(
        'id' => 3, 'user_id' => 11, 'name' => 'test rule', 'trigger_event' => 'lead.created',
        'conditions_json' => json_encode($conditions), 'actions_json' => json_encode($actions), 'status' => 'active',
    ), $overrides);
}

echo "-- conditions ------------------------------------------------------\n";

// operator matrix: [op, left, right, expected]
$matrix = array(
    array('eq', 70, 70, true), array('eq', '70', 70, true), array('eq', 'Hot', 'hot', true),
    array('eq', 'hot', 'cold', false), array('eq', null, 'x', false), array('eq', '', '', false),
    array('neq', 'hot', 'cold', true), array('neq', 70, 70, false), array('neq', null, 'x', false),
    array('gt', 71, 70, true), array('gt', 70, 70, false), array('gt', 'abc', 70, false), array('gt', null, 70, false),
    array('gte', 70, 70, true), array('gte', 69, 70, false),
    array('lt', 69, 70, true), array('lt', 70, 70, false),
    array('lte', 70, 70, true), array('lte', 71, 70, false),
    array('contains', 'Double Garden room', 'garden', true), array('contains', 'suite', 'garden', false),
    array('contains', array('a', 'B'), 'b', true), array('contains', 'anything', '', false), array('contains', null, 'x', false),
    array('in', 'fb', 'fb,ig,wa', true), array('in', 'tg', 'fb,ig,wa', false),
    array('in', 'IG', array('fb', 'ig'), true), array('in', null, 'fb', false),
    array('exists', 'x', true, true), array('exists', null, true, false), array('exists', '', true, false),
    array('exists', null, false, true), array('exists', 'x', false, false),
    array('nonsense_op', 'x', 'x', false),
);
$mfail = 0;
foreach ($matrix as $m) {
    $got = Automation_engine::compare($m[0], $m[1], $m[2]);
    if ($got !== $m[3]) { $mfail++; bad('operator ' . $m[0] . ' ' . json_encode($m[1]) . ' / ' . json_encode($m[2]), 'got ' . var_export($got, true)); }
}
if ($mfail === 0) ok('operator matrix: ' . count($matrix) . ' cases');

is_same('dotted field resolves', 'Nada', Automation_engine::resolve_field(array('subscriber' => array('full_name' => 'Nada')), 'subscriber.full_name'));
is_same('missing field is null', null, Automation_engine::resolve_field(array('a' => 1), 'a.b.c'));
is_same('empty path is null', null, Automation_engine::resolve_field(array('a' => 1), ''));

$gw = new FakeGateway();
$e = mk_engine($gw);
$data = array('lead_score' => 75, 'platform' => 'fb');
is_true('AND: all conditions true', $e->conditions_match(array(array('field' => 'lead_score', 'op' => 'gte', 'value' => 70), array('field' => 'platform', 'op' => 'eq', 'value' => 'fb')), $data)['matched']);
is_false('AND: one false fails the rule', $e->conditions_match(array(array('field' => 'lead_score', 'op' => 'gte', 'value' => 70), array('field' => 'platform', 'op' => 'eq', 'value' => 'wa')), $data)['matched']);
is_true('no conditions means always', $e->conditions_match(array(), $data)['matched']);
is_true('conditions accept a JSON string', $e->conditions_match('[{"field":"lead_score","op":"gte","value":70}]', $data)['matched']);
is_true('conditions accept {match, conditions}', $e->conditions_match(array('match' => 'all', 'conditions' => array(array('field' => 'lead_score', 'op' => 'gte', 'value' => 70))), $data)['matched']);
is_false('unknown field cannot match', $e->conditions_match(array(array('field' => 'no_such_field', 'op' => 'eq', 'value' => 'x')), $data)['matched']);
is_same('failed condition is reported', 'platform', $e->conditions_match(array(array('field' => 'platform', 'op' => 'eq', 'value' => 'wa')), $data)['failed'][0]['field']);

echo "-- evaluation data -------------------------------------------------\n";
$gw = new FakeGateway();
$gw->context = array('subscriber' => array('id' => 21, 'full_name' => 'Nada', 'lead_score' => 9), 'promote' => array('lead_score' => 9, 'platform' => 'wa'));
$d = mk_engine($gw)->evaluation_data(mk_event());
is_same('payload wins over promoted context', 75, $d['lead_score']);
is_same('context fills what the payload omits', 21, $d['subscriber']['id']);
is_same('event_type is available as a field', 'lead.created', $d['event_type']);

echo "-- action dispatch -------------------------------------------------\n";
$gw = new FakeGateway();
$e = mk_engine($gw);
$rule = mk_rule(array(), array(
    array('type' => 'add_label', 'label' => 'Hot lead'),
    array('type' => 'update_lead_score', 'delta' => 10),
    array('type' => 'create_crm_deal', 'title' => 'Deal for {{full_name}}'),
    array('type' => 'notify_team', 'channel' => 'whatsapp', 'to' => '201000000000', 'text' => 'New lead {{full_name}}'),
    array('type' => 'call_webhook', 'url' => 'https://example.test/hook'),
));
$r = $e->run_rule($rule, mk_event());
is_same('all five actions ran', 'success', $r['status']);
is_same('gateway saw each action once', array('add_label', 'update_lead_score', 'create_crm_deal', 'notify_team', 'call_webhook'), $gw->types());
is_same('label text passed through', 'Hot lead', $gw->calls[0][1][2]);
is_same('{{field}} is filled from the event', 'Deal for Nada', $gw->calls[2][1][2]['title']);
is_same('every action is logged', 5, count($gw->actions));
is_same('run detail counts the work', 'success', $gw->runs[$r['run_id']]['status']);

$gw = new FakeGateway();
$r = mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'teleport_customer'))), mk_event());
is_same('unknown action fails the action', 'failed', $gw->actions[0]['status']);
is_same('unknown action is named in the error', 'unknown action type: teleport_customer', $gw->actions[0]['error']);
is_same('unknown action does not abort the run', 'failed', $r['status']);

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'call_webhook', 'url' => 'http://insecure.test/hook'))), mk_event());
is_same('plain http webhook is refused', 'webhook URL must start with https://', $gw->actions[0]['error']);
is_same('refused webhook was never called', 0, count($gw->calls));

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'add_label', 'label' => 'x'))), mk_event(array('payload' => '{}', 'subject_type' => 'deal')));
is_same('action without a customer is skipped, not failed', 'skipped', $gw->actions[0]['status']);

echo "-- channel policy --------------------------------------------------\n";
$gw = new FakeGateway();
$gw->policy = array('allowed' => false, 'reason' => 'automation_outside_24h_window', 'window' => '24h+', 'messaging_type' => null, 'tag' => null);
$r = mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'send_message', 'text' => 'hi'))), mk_event());
is_same('denied send is logged as skipped_policy', 'skipped_policy', $gw->actions[0]['status']);
is_same('the policy reason is recorded', 'automation_outside_24h_window', $gw->actions[0]['error']);
is_same('NOTHING was sent', 0, $gw->sent());
is_same('denial is not retried', 0, $gw->actions[0]['retry_count']);
is_same('a run with only a denied send is not a success', 'skipped', $r['status']);

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'send_message', 'text' => 'hi'))), mk_event());
is_same('allowed send reaches the channel', 1, $gw->sent());
$policy_ctx = null;
foreach ($gw->calls as $c) if ($c[0] === 'policy_decide') $policy_ctx = $c[1][1];
is_same('policy is always asked as automation', 'automation', $policy_ctx['sender']);
is_same('policy is asked for the right channel', 'fb', $gw->calls[0][1][0]);

$gw = new FakeGateway();
$gw->context = array('promote' => array('opted_out' => true, 'last_inbound_at' => '2026-09-24 09:00:00'));
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'send_message', 'text' => 'hi'))), mk_event());
$ctx2 = null;
foreach ($gw->calls as $c) if ($c[0] === 'policy_decide') $ctx2 = $c[1][1];
is_true('opted_out is passed to the policy layer', $ctx2['opted_out']);

echo "-- idempotency -----------------------------------------------------\n";
$gw = new FakeGateway();
$e = mk_engine($gw);
$rule = mk_rule(array(), array(array('type' => 'add_label', 'label' => 'Hot lead')));
$first = $e->run_rule($rule, mk_event());
$second = $e->run_rule($rule, mk_event());
is_same('first run succeeds', 'success', $first['status']);
is_same('replay of the same event is a duplicate', 'duplicate', $second['status']);
is_same('the action ran exactly once', 1, count($gw->calls));
$third = $e->run_rule($rule, mk_event(array('id' => 8)));
is_same('a different event does run again', 'success', $third['status']);
is_same('now the action has run twice', 2, count($gw->calls));

echo "-- tenant isolation ------------------------------------------------\n";
$gw = new FakeGateway();
$r = mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'add_label', 'label' => 'x')), array('user_id' => 12)), mk_event());
is_same('a rule refuses another tenant event', 'tenant_mismatch', $r['detail']);
is_same('nothing was done for the wrong tenant', 0, count($gw->calls));
is_same('no run row was claimed', 0, count($gw->runs));
is_true('the refusal is logged', count($gw->logs) > 0);

$gw = new FakeGateway();
$gw->rules = array(
    mk_rule(array(), array(array('type' => 'add_label', 'label' => 'A')), array('id' => 1, 'user_id' => 11)),
    mk_rule(array(), array(array('type' => 'add_label', 'label' => 'B')), array('id' => 2, 'user_id' => 12)),
    mk_rule(array(), array(array('type' => 'add_label', 'label' => 'C')), array('id' => 3, 'user_id' => 11, 'trigger_event' => 'order.created')),
    mk_rule(array(), array(array('type' => 'add_label', 'label' => 'D')), array('id' => 4, 'user_id' => 11, 'status' => 'paused')),
);
$sum = mk_engine($gw)->process_event(mk_event());
is_same('only this tenant + this trigger + active rules run', 1, $sum['rules']);
is_same('and it was the right one', 'A', $gw->calls[0][1][2]);

$gw = new FakeGateway();
$r = mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'add_label', 'label' => 'x')), array('status' => 'paused')), mk_event());
is_same('a paused rule never runs', 'rule_not_active', $r['detail']);

echo "-- retry and backoff -----------------------------------------------\n";
$gw = new FakeGateway();
$gw->fail_queue['call_webhook'] = array(array(false, 'timeout', true), array(false, 'timeout', true), array(true, null, false));
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'call_webhook', 'url' => 'https://example.test/h'))), mk_event());
is_same('retryable failure is retried until it works', 'success', $gw->actions[0]['status']);
is_same('retry count is recorded', 2, $gw->actions[0]['retry_count']);
is_same('backoff grew between attempts', array(1, 3), $gw->slept);

$gw = new FakeGateway();
$gw->fail_queue['call_webhook'] = array(array(false, 'timeout', true), array(false, 'timeout', true), array(false, 'timeout', true), array(true, null, false));
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'call_webhook', 'url' => 'https://example.test/h'))), mk_event());
is_same('retries stop at MAX_ATTEMPTS', 'failed', $gw->actions[0]['status']);
is_same('exactly 3 attempts were made', 3, count(array_filter($gw->calls, function ($c) { return $c[0] === 'call_webhook'; })));
is_same('the last error is kept', 'timeout', $gw->actions[0]['error']);

$gw = new FakeGateway();
$gw->fail_queue['call_webhook'] = array(array(false, 'HTTP 400 bad payload', false), array(true, null, false));
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'call_webhook', 'url' => 'https://example.test/h'))), mk_event());
is_same('a non-retryable failure is not retried', 0, $gw->actions[0]['retry_count']);
is_same('and it stays failed', 'failed', $gw->actions[0]['status']);
is_same('no backoff was waited', array(), $gw->slept);

echo "-- conditions gate the actions -------------------------------------\n";
$gw = new FakeGateway();
$r = mk_engine($gw)->run_rule(mk_rule(array(array('field' => 'lead_score', 'op' => 'gte', 'value' => 90)), array(array('type' => 'add_label', 'label' => 'x'))), mk_event());
is_same('rule whose conditions fail is skipped', 'skipped', $r['status']);
is_same('and no action ran', 0, count($gw->calls));
is_true('the reason names the field', strpos($r['detail'], 'lead_score') !== false);
is_same('the skip is still recorded as a run', 'skipped', $gw->runs[$r['run_id']]['status']);

echo "-- dry run ---------------------------------------------------------\n";
$gw = new FakeGateway();
$gw->policy = array('allowed' => false, 'reason' => 'opted_out', 'window' => 'n/a', 'messaging_type' => null, 'tag' => null);
$dry = mk_engine($gw)->dry_run(mk_rule(
    array(array('field' => 'lead_score', 'op' => 'gte', 'value' => 70)),
    array(array('type' => 'add_label', 'label' => 'Hot'), array('type' => 'send_message', 'text' => 'hello'), array('type' => 'nope'))
), mk_event());
is_true('dry run reports the match', $dry['matched']);
is_same('dry run describes the first action', 'Tag as "Hot"', $dry['actions'][0]['summary']);
is_same('dry run shows a policy denial', 'not send (opted_out)', $dry['actions'][1]['would']);
is_same('dry run flags an unknown action', 'fail (unknown action)', $dry['actions'][2]['would']);
is_same('dry run changed nothing', 0, count($gw->actions) + count($gw->runs) + $gw->sent());


echo "-- Task 24: new actions ---------------------------------------------\n";

$gw = new FakeGateway();
$e = mk_engine($gw);
$r = $e->run_rule(mk_rule(array(), array(array('type' => 'assign_agent', 'agent_id' => 9))), mk_event());
is_same('assign_agent runs', 'success', $r['status']);
is_same('assign_agent got the customer + agent id', array(11, 21, 9), $gw->calls[0][1]);

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'assign_agent', 'agent_id' => 9))), mk_event(array('payload' => '{}', 'subject_type' => 'deal')));
is_same('assign_agent without a customer is skipped, not failed', 'skipped', $gw->actions[0]['status']);

$gw = new FakeGateway();
$r = mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'update_field', 'field' => 'email', 'value' => 'x@example.test'))), mk_event());
is_same('update_field runs', 'success', $r['status']);
is_same('update_field defaults target to customer', array(11, 'customer', 21, 'email', 'x@example.test'), $gw->calls[0][1]);

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'update_field', 'value' => 'x'))), mk_event());
is_same('update_field needs a field name', 'no field given', $gw->actions[0]['error']);

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'update_field', 'field' => 'title', 'value' => 'New title', 'target' => 'deal', 'deal_id' => 55))), mk_event());
is_same('update_field(target=deal) uses the given deal_id', array(11, 'deal', 55, 'title', 'New title'), $gw->calls[0][1]);

$gw = new FakeGateway();
$r = mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'notify_customer_email', 'text' => 'Hello {{full_name}}'))), mk_event(array('payload' => json_encode(array('email' => 'nada@example.test', 'full_name' => 'Nada')))));
is_same('notify_customer_email runs', 'success', $r['status']);
is_same('notify_customer_email uses the customer email and fills the template', array(11, 'nada@example.test', 'An update for you', 'Hello Nada'), $gw->calls[0][1]);

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'notify_customer_email', 'text' => 'hi'))), mk_event(array('payload' => '{}')));
is_same('notify_customer_email without an email is skipped, not failed', 'skipped', $gw->actions[0]['status']);

foreach (array('assign_agent', 'update_field', 'notify_customer_email') as $t) {
    is_true('action_catalogue lists ' . $t, array_key_exists($t, Automation_engine::action_catalogue()));
}

echo "-- Task 24: new trigger types ----------------------------------------\n";

is_true('trigger_catalogue lists keyword/tag/inactivity/score_threshold', count(array_diff(array('keyword', 'tag', 'inactivity', 'score_threshold'), array_keys(Automation_engine::trigger_catalogue()))) === 0);

$gw = new FakeGateway();
$gw->rules = array(
    mk_rule(array(array('field' => 'text', 'op' => 'contains', 'value' => 'price')), array(array('type' => 'add_label', 'label' => 'asked price')), array('id' => 1, 'user_id' => 11, 'trigger_event' => 'keyword')),
);
$sum = mk_engine($gw)->process_event(mk_event(array('event_type' => 'message.received', 'payload' => json_encode(array('text' => 'what is the price?', 'subscriber_id' => 21)))));
is_same('a keyword rule is woken by message.received', 1, $sum['rules']);
is_same('and its condition (contains) decided the match', 'success', $sum['success'] > 0 ? 'success' : 'not run');

$gw = new FakeGateway();
$gw->rules = array(mk_rule(array(), array(array('type' => 'add_label', 'label' => 'x')), array('id' => 2, 'user_id' => 11, 'trigger_event' => 'tag')));
$sum2 = mk_engine($gw)->process_event(mk_event(array('event_type' => 'customer.created')));
is_same('a tag rule is woken by customer.created', 1, $sum2['rules']);

$gw = new FakeGateway();
$gw->rules = array(mk_rule(array(), array(array('type' => 'add_label', 'label' => 'x')), array('id' => 3, 'user_id' => 11, 'trigger_event' => 'score_threshold')));
$sum3 = mk_engine($gw)->process_event(mk_event(array('event_type' => 'lead.qualified')));
is_same('a score_threshold rule is woken by lead.qualified', 1, $sum3['rules']);

$gw = new FakeGateway();
$gw->rules = array(mk_rule(array(), array(array('type' => 'add_label', 'label' => 'x')), array('id' => 4, 'user_id' => 11, 'trigger_event' => 'inactivity')));
$sum4 = mk_engine($gw)->process_event(mk_event(array('event_type' => 'inactivity')));
is_same('an inactivity rule is a plain event_type match (no alias needed)', 1, $sum4['rules']);

$gw = new FakeGateway();
$gw->rules = array(
    mk_rule(array(), array(array('type' => 'add_label', 'label' => 'literal')), array('id' => 5, 'user_id' => 11, 'trigger_event' => 'lead.created')),
    mk_rule(array(), array(array('type' => 'add_label', 'label' => 'via-alias')), array('id' => 6, 'user_id' => 11, 'trigger_event' => 'tag')),
);
$sum5 = mk_engine($gw)->process_event(mk_event(array('event_type' => 'lead.created')));
is_same('a literal-match rule and an aliased rule both fire, exactly once each', 2, $sum5['rules']);

$gw = new FakeGateway();
$gw->rules = array(mk_rule(array(), array(array('type' => 'add_label', 'label' => 'x')), array('id' => 7, 'user_id' => 11, 'trigger_event' => 'keyword')));
$sum6 = mk_engine($gw)->process_event(mk_event(array('event_type' => 'order.created')));
is_same('an unrelated event does not wake a keyword rule', 0, $sum6['rules']);

echo "-- Task 24: is_inactive (pure) ----------------------------------------\n";
$now = strtotime('2026-09-28 12:00:00');
is_true('25h-old inbound at a 24h threshold is inactive', Automation_engine::is_inactive('2026-09-27 11:00:00', 24, $now));
is_true('1h-old inbound is not inactive', !Automation_engine::is_inactive('2026-09-28 11:00:00', 24, $now));
is_true('empty timestamp is never inactive', !Automation_engine::is_inactive('', 24, $now));
is_true('null-ish timestamp is never inactive', !Automation_engine::is_inactive(null, 24, $now));
is_true('unparseable timestamp is never inactive', !Automation_engine::is_inactive('not a date', 24, $now));
is_true('a zero/negative threshold never fires', !Automation_engine::is_inactive('2020-01-01 00:00:00', 0, $now));
is_true('exactly at the threshold counts as inactive', Automation_engine::is_inactive('2026-09-27 12:00:00', 24, $now));


echo "-- Task 24 review fix: update_field numeric validation ----------------\n";
$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'update_field', 'field' => 'lead_score', 'value' => 'not-a-number'))), mk_event());
is_same('non-numeric lead_score is rejected', 'failed', $gw->actions[0]['status']);
is_true('the error names the field', strpos($gw->actions[0]['error'], 'lead_score') !== false);
is_same('the gateway was never called', 0, count($gw->calls));

$gw = new FakeGateway();
$r = mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'update_field', 'field' => 'lead_score', 'value' => '42'))), mk_event());
is_same('numeric lead_score (as a string) is accepted', 'success', $r['status']);

$gw = new FakeGateway();
mk_engine($gw)->run_rule(mk_rule(array(), array(array('type' => 'update_field', 'field' => 'value', 'value' => 'abc', 'target' => 'deal', 'deal_id' => 55))), mk_event());
is_same('non-numeric deal value is rejected', 'failed', $gw->actions[0]['status']);

echo "-- Task 24 review fix: human.assigned from assign_agent (Automation_gateway) --------\n";
// This is exercised against the REAL gateway class (not FakeGateway) purely as a static
// check: the fake gateway intentionally has no database, and a DB-backed behavioural test is
// out of scope for this dependency-free suite (see tests/events/run.php for the equivalent
// static wiring check on Inbox::assign()).
$gw_src = file_get_contents(__DIR__ . '/../../application/libraries/Automation_gateway.php');
$pos = strpos($gw_src, 'function assign_agent(');
is_true('Automation_gateway::assign_agent() exists', $pos !== false);
is_true('assign_agent() emits human.assigned for consistency with Inbox::assign()',
    strpos(substr($gw_src, $pos, 2500), "'human.assigned'") !== false);

echo "-- Task 24 review fix: inactivity tenant filter + once-per-spell dedup (pure) --------\n";

$rules_none = array(mk_rule(array(), array(), array('id' => 1, 'user_id' => 11, 'trigger_event' => 'lead.created', 'status' => 'active')));
is_same('a tenant with no inactivity rule is not in the scan set', array(), Automation_engine::inactivity_tenants($rules_none));

$rules_paused = array(mk_rule(array(), array(), array('id' => 1, 'user_id' => 11, 'trigger_event' => 'inactivity', 'status' => 'paused')));
is_same('a PAUSED inactivity rule does not add its tenant either', array(), Automation_engine::inactivity_tenants($rules_paused));

$rules_mixed = array(
    array('user_id' => 11, 'status' => 'active', 'trigger_event' => 'inactivity'),
    array('user_id' => 11, 'status' => 'active', 'trigger_event' => 'inactivity'), // duplicate rule, same tenant
    array('user_id' => 12, 'status' => 'active', 'trigger_event' => 'lead.created'), // different trigger
    array('user_id' => 13, 'status' => 'active', 'trigger_event' => 'inactivity'),
);
is_same('only tenants with an active inactivity rule are returned, once each', array(11, 13), Automation_engine::inactivity_tenants($rules_mixed));

$now = strtotime('2026-09-28 12:00:00');
$conv = array('id' => 501, 'last_inbound_at' => '2026-09-27 00:00:00'); // 36h idle, past a 24h threshold
$first = Automation_engine::inactivity_emit_targets(array($conv), array(), 24, $now);
is_same('scan #1: the idle conversation with no prior event is a target', array('501'), $first);

// scan #2 mimics running scan_inactivity() again a minute later, now WITH the event that
// scan #1's emit would have written (created "now", well after last_inbound_at).
$events_after_first_scan = array(array('subject_id' => '501', 'created_at' => date('Y-m-d H:i:s', $now)));
$second = Automation_engine::inactivity_emit_targets(array($conv), $events_after_first_scan, 24, $now + 60);
is_same('scan #2 (same idle spell): the same conversation is NOT emitted again', array(), $second);

// a real inbound message afterwards moves last_inbound_at forward -- eligible again once idle
$conv_after_reply = array('id' => 501, 'last_inbound_at' => date('Y-m-d H:i:s', $now + 3600));
$still_recent = Automation_engine::inactivity_emit_targets(array($conv_after_reply), $events_after_first_scan, 24, $now + 3700);
is_same('right after a fresh inbound message the conversation is not idle yet', array(), $still_recent);
$idle_again = Automation_engine::inactivity_emit_targets(array($conv_after_reply), $events_after_first_scan, 24, $now + 3600 + (25 * 3600));
is_same('once idle again (new spell, after the old event) it is a target again', array('501'), $idle_again);

$conv_other = array('id' => 502, 'last_inbound_at' => '2026-09-27 00:00:00');
$mix = Automation_engine::inactivity_emit_targets(array($conv, $conv_other), $events_after_first_scan, 24, $now + 60);
is_same('a DIFFERENT idle conversation with no prior event is still a target', array('502'), $mix);


echo "-- Task 24 review round 2: no starvation -- real query, SQLite -------\n";
// Round 1 moved the "already flagged" exclusion into PHP, AFTER the SQL's LIMIT had already
// been filled by already-flagged rows -- a tenant with >= $per_tenant flagged idle
// conversations would starve every newer idle conversation forever. The fix puts the
// exclusion inside Automation_engine::inactivity_candidate_sql()'s WHERE clause, before
// LIMIT. This runs that EXACT SQL string (the same one production sends to MariaDB) against
// an in-memory SQLite database, so it is a real query-execution test, not a hand-built-array
// stand-in for one.
$sql_shape = Automation_engine::inactivity_candidate_sql();
$not_exists_pos = strpos($sql_shape, 'NOT EXISTS');
$limit_pos = strpos($sql_shape, 'LIMIT');
is_true('inactivity_candidate_sql() has a NOT EXISTS exclusion', $not_exists_pos !== false);
is_true('...and it sits BEFORE LIMIT (inside WHERE, not a post-filter)', $not_exists_pos !== false && $limit_pos !== false && $not_exists_pos < $limit_pos);

if (!extension_loaded('pdo_sqlite')) {
    bad('inactivity SQL: no starvation (SQLite-backed)', 'pdo_sqlite extension not available in this PHP -- cannot run a real query test');
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE conversations (id INTEGER PRIMARY KEY, user_id INTEGER, platform TEXT, status TEXT, last_inbound_at TEXT)");
    $pdo->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, user_id INTEGER, event_type TEXT, subject_type TEXT, subject_id TEXT, created_at TEXT)");

    $per_tenant = 3; // small on purpose: exactly the LIMIT round 1's bug filled with junk
    $user_id = 11;
    $now_ts = strtotime('2026-09-28 12:00:00');
    $cutoff = date('Y-m-d H:i:s', $now_ts - (24 * 3600));

    // 3 conversations that are idle AND already flagged for this idle spell (event created
    // after their last_inbound_at) -- exactly $per_tenant of them, the round-1 starvation case.
    $flagged_last_inbound = date('Y-m-d H:i:s', $now_ts - (48 * 3600));
    $flagged_event_created = date('Y-m-d H:i:s', $now_ts - (47 * 3600));
    for ($i = 1; $i <= $per_tenant; $i++) {
        $pdo->exec("INSERT INTO conversations (id, user_id, platform, status, last_inbound_at) VALUES ($i, $user_id, 'wa', 'open', '$flagged_last_inbound')");
        $pdo->exec("INSERT INTO events (user_id, event_type, subject_type, subject_id, created_at) VALUES ($user_id, 'inactivity', 'conversation', '$i', '$flagged_event_created')");
    }
    // ...plus ONE genuinely new-idle conversation for the SAME tenant, never flagged.
    $new_conv_id = 999;
    $new_last_inbound = date('Y-m-d H:i:s', $now_ts - (30 * 3600));
    $pdo->exec("INSERT INTO conversations (id, user_id, platform, status, last_inbound_at) VALUES ($new_conv_id, $user_id, 'wa', 'open', '$new_last_inbound')");

    $sql = Automation_engine::inactivity_candidate_sql();
    $run_query = function () use ($pdo, $sql, $user_id, $cutoff, $per_tenant) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($user_id, $cutoff, $per_tenant));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $tick1 = $run_query();
    $ids1 = array_map(function ($r) { return (int) $r['id']; }, $tick1);
    is_same('tick 1: the LIMIT is not filled by already-flagged rows', array($new_conv_id), $ids1);
    is_true('tick 1: the new conversation got exactly one row back (not zero, not starved)', count($tick1) === 1);

    // Simulate the worker's own emit for what tick 1 found.
    foreach ($tick1 as $row) {
        $pdo->exec("INSERT INTO events (user_id, event_type, subject_type, subject_id, created_at) VALUES ({$row['user_id']}, 'inactivity', 'conversation', '{$row['id']}', '" . date('Y-m-d H:i:s', $now_ts) . "')");
    }

    $tick2 = $run_query();
    is_same('tick 2 (same idle spell, one minute later): nothing left to emit -- no re-fire', array(), $tick2);

    // A real inbound message re-arms the same conversation for its NEXT idle spell.
    $pdo->exec("UPDATE conversations SET last_inbound_at = '" . date('Y-m-d H:i:s', $now_ts) . "' WHERE id = $new_conv_id");
    $cutoff_later = date('Y-m-d H:i:s', $now_ts + (25 * 3600) - (24 * 3600));
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array($user_id, $cutoff_later, $per_tenant));
    $tick3 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    is_same('once idle again after a fresh inbound message, it is a candidate again', array($new_conv_id), array_map(function ($r) { return (int) $r['id']; }, $tick3));
}

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL === 0 && $PASS > 0 ? 0 : 1);
