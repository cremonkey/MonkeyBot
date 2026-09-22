<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Automation Engine — Trigger -> Conditions -> Actions (Phase 10.5, brief 23).
 *
 * A rule listens to ONE event type (its trigger), checks a list of conditions (AND), and
 * runs a list of actions. Rules are owned by a tenant and only ever see that tenant's
 * events; see run_rule()'s tenant guard, which is a hard stop, not a filter.
 *
 * DESIGN: everything with a side effect lives behind Automation_gateway (DB writes, sends,
 * webhooks, sleeps). This class holds only decisions: which rules match, whether conditions
 * pass, whether a send is allowed by channel policy, what to retry and how often, what to
 * record. That is why tests/automation/run.php can exercise the whole engine with a fake
 * gateway, no database and no network.
 *
 * GUARANTEES
 *  - Idempotent per (rule, event). automation_runs has UNIQUE(rule_id, event_id); a claim
 *    that loses the race returns 0 and the engine reports 'duplicate' without acting.
 *  - No silent failure (brief 51). Every run gets an automation_runs row and every action
 *    attempt an automation_action_log row, including the ones that were skipped and why.
 *  - A send_message action ALWAYS goes through channel_policy_decide() with
 *    sender = 'automation'. A denial is recorded as 'skipped_policy' with the policy's
 *    reason and nothing is sent. Denials are never retried.
 *  - An unknown action type fails that action; it does not abort the run.
 *  - Retryable failures (transport-level) are retried up to MAX_ATTEMPTS with backoff.
 */
class Automation_engine
{
    /** total attempts per action, including the first */
    const MAX_ATTEMPTS = 3;

    /** seconds to wait before attempt 2 and attempt 3 */
    public static $backoff = array(1, 3);

    /** action statuses that count as "the action did its job" */
    const ST_SUCCESS = 'success';
    const ST_FAILED  = 'failed';
    const ST_SKIPPED = 'skipped';
    const ST_POLICY  = 'skipped_policy';

    /** @var object Automation_gateway or a test double */
    protected $gw;

    /** @var array per-event context cache, keyed by event id */
    protected $ctx_cache = array();

    public function __construct($params = array())
    {
        if (isset($params['gateway']) && is_object($params['gateway'])) {
            $this->gw = $params['gateway'];
            return;
        }
        require_once APPPATH . 'libraries/Automation_gateway.php';
        $this->gw = new Automation_gateway();
    }

    // =====================================================================
    // Conditions — pure, no side effects
    // =====================================================================

    /** Operators a rule may use, with owner-language labels for the UI. */
    public static function operators()
    {
        return array(
            'eq'       => 'is',
            'neq'      => 'is not',
            'gt'       => 'is more than',
            'gte'      => 'is at least',
            'lt'       => 'is less than',
            'lte'      => 'is at most',
            'contains' => 'contains',
            'in'       => 'is one of',
            'exists'   => 'has a value',
        );
    }

    /**
     * Read a dotted path out of the evaluation data ('lead_score', 'subscriber.full_name').
     * Returns null when any step is missing — callers treat null as "cannot verify".
     */
    public static function resolve_field($data, $path)
    {
        $path = trim((string) $path);
        if ($path === '') return null;
        $cur = $data;
        foreach (explode('.', $path) as $key) {
            if (is_array($cur) && array_key_exists($key, $cur)) { $cur = $cur[$key]; continue; }
            return null;
        }
        return $cur;
    }

    /**
     * Apply one operator. String comparisons are case-insensitive on purpose: the owner
     * types 'hot lead' in the rule and the data says 'Hot Lead'. Numeric operators on a
     * non-numeric value are FALSE, never a PHP-cast surprise.
     */
    public static function compare($op, $left, $right)
    {
        $s = function ($v) {
            if (is_bool($v)) return $v ? '1' : '0';
            if (is_array($v)) return '';
            return mb_strtolower(trim((string) $v));
        };
        $present = !($left === null || $left === '' || (is_array($left) && !$left));

        switch ($op) {
            case 'exists':
                $want = !($right === false || $right === 0 || $right === '0' || $s($right) === 'false' || $s($right) === 'no');
                return $want ? $present : !$present;

            case 'eq':
            case 'neq':
                if (!$present) return false;   // cannot verify => no match, for both directions
                $eq = (is_numeric($left) && is_numeric($right))
                    ? ((float) $left === (float) $right)
                    : ($s($left) === $s($right));
                return $op === 'eq' ? $eq : !$eq;

            case 'gt': case 'gte': case 'lt': case 'lte':
                if (!is_numeric($left) || !is_numeric($right)) return false;
                $l = (float) $left; $r = (float) $right;
                if ($op === 'gt')  return $l >  $r;
                if ($op === 'gte') return $l >= $r;
                if ($op === 'lt')  return $l <  $r;
                return $l <= $r;

            case 'contains':
                if (!$present) return false;
                if (is_array($left)) {
                    foreach ($left as $v) if ($s($v) === $s($right)) return true;
                    return false;
                }
                if ($s($right) === '') return false;
                return mb_strpos($s($left), $s($right)) !== false;

            case 'in':
                if (!$present) return false;
                $list = is_array($right) ? $right : explode(',', (string) $right);
                foreach ($list as $v) if ($s($v) === $s($left)) return true;
                return false;
        }
        return false;   // unknown operator never matches
    }

    /**
     * Evaluate a rule's condition list (AND of every row). An empty list matches: a rule
     * with no conditions is "whenever this happens".
     *
     * @param array|string $conditions JSON string or array. Accepts either a bare list of
     *                                 {field, op, value} rows or {match:'all', conditions:[...]}.
     * @return array{matched:bool, failed:array} failed = the rows that did not pass
     */
    public function conditions_match($conditions, array $data)
    {
        $rows = self::normalise_conditions($conditions);
        $failed = array();
        foreach ($rows as $i => $row) {
            $op = isset($row['op']) ? (string) $row['op'] : 'eq';
            $left = self::resolve_field($data, isset($row['field']) ? $row['field'] : '');
            $right = isset($row['value']) ? $row['value'] : null;
            if (!self::compare($op, $left, $right)) {
                $failed[] = array('index' => $i, 'field' => isset($row['field']) ? $row['field'] : '', 'op' => $op, 'value' => $right, 'actual' => $left);
            }
        }
        return array('matched' => empty($failed), 'failed' => $failed);
    }

    /** @return array list of {field, op, value} */
    public static function normalise_conditions($conditions)
    {
        if (is_string($conditions)) {
            $d = json_decode($conditions, true);
            $conditions = is_array($d) ? $d : array();
        }
        if (!is_array($conditions)) return array();
        if (isset($conditions['conditions']) && is_array($conditions['conditions'])) $conditions = $conditions['conditions'];
        $out = array();
        foreach ($conditions as $row) {
            if (!is_array($row) || !isset($row['field'])) continue;
            if (trim((string) $row['field']) === '') continue;
            $out[] = $row;
        }
        return $out;
    }

    /** @return array list of action rows with a 'type' */
    public static function normalise_actions($actions)
    {
        if (is_string($actions)) {
            $d = json_decode($actions, true);
            $actions = is_array($d) ? $d : array();
        }
        if (!is_array($actions)) return array();
        if (isset($actions['actions']) && is_array($actions['actions'])) $actions = $actions['actions'];
        $out = array();
        foreach ($actions as $row) {
            if (!is_array($row) || empty($row['type'])) continue;
            $out[] = $row;
        }
        return $out;
    }

    // =====================================================================
    // Evaluation data
    // =====================================================================

    /**
     * The flat namespace a rule's conditions are written against:
     *   event_type, subject_type, subject_id, correlation_id  (always)
     *   every key of the event payload                        (e.g. lead_score, platform, text)
     *   subscriber.*, deal.*                                  (loaded, tenant-scoped, by the gateway)
     *   promoted context values (lead_score, platform, ...) when the payload did not carry them
     */
    public function evaluation_data(array $event)
    {
        $payload = array();
        if (isset($event['payload'])) {
            $payload = is_array($event['payload']) ? $event['payload'] : json_decode((string) $event['payload'], true);
            if (!is_array($payload)) $payload = array();
        }

        $key = isset($event['id']) ? (string) $event['id'] : spl_object_hash((object) $event);
        if (!array_key_exists($key, $this->ctx_cache)) {
            $ctx = $this->gw->context((int) $event['user_id'], $event);
            $this->ctx_cache[$key] = is_array($ctx) ? $ctx : array();
        }
        $ctx = $this->ctx_cache[$key];

        $base = array(
            'event_type'     => isset($event['event_type']) ? $event['event_type'] : '',
            'subject_type'   => isset($event['subject_type']) ? $event['subject_type'] : '',
            'subject_id'     => isset($event['subject_id']) ? $event['subject_id'] : '',
            'correlation_id' => isset($event['correlation_id']) ? $event['correlation_id'] : null,
        );

        // promoted convenience fields, only where the payload is silent
        $promoted = array();
        foreach (array('lead_score', 'platform', 'full_name', 'email', 'phone', 'subscriber_id', 'last_inbound_at', 'opted_out') as $f) {
            if (isset($ctx['promote'][$f])) $promoted[$f] = $ctx['promote'][$f];
        }

        $named = array();
        foreach (array('subscriber', 'deal') as $k) if (isset($ctx[$k])) $named[$k] = $ctx[$k];

        return array_merge($base, $promoted, $payload, $named);
    }

    // =====================================================================
    // Dry run — what WOULD happen, without doing any of it
    // =====================================================================

    /**
     * Used by the "Test with a sample event" button. Evaluates the real conditions against
     * a real (or hand-written) event and describes the actions, including the channel
     * policy decision for a send, but executes nothing and writes nothing.
     */
    public function dry_run(array $rule, array $event)
    {
        $data = $this->evaluation_data($event);
        $verdict = $this->conditions_match(isset($rule['conditions_json']) ? $rule['conditions_json'] : array(), $data);

        $actions = array();
        foreach (self::normalise_actions(isset($rule['actions_json']) ? $rule['actions_json'] : array()) as $i => $action) {
            $row = array(
                'index'   => $i,
                'type'    => $action['type'],
                'summary' => $this->describe_action($action, $data),
                'would'   => $verdict['matched'] ? 'run' : 'not run (conditions not met)',
            );
            if ($action['type'] === 'send_message') {
                $decision = $this->policy_for($action, $data);
                $row['policy'] = $decision;
                if ($verdict['matched'] && !$decision['allowed']) {
                    $row['would'] = 'not send (' . $decision['reason'] . ')';
                }
            }
            if (!in_array($action['type'], array_keys(self::action_catalogue()), true)) {
                $row['would'] = 'fail (unknown action)';
            }
            $actions[] = $row;
        }

        return array(
            'matched'  => $verdict['matched'],
            'failed'   => $verdict['failed'],
            'actions'  => $actions,
            'data'     => $data,
        );
    }

    /** action type => owner-language label (brief 46). Also the allow-list of known types. */
    public static function action_catalogue()
    {
        return array(
            'add_label'          => 'Tag the customer',
            'update_lead_score'  => 'Change the lead score',
            'create_crm_deal'    => 'Create a deal in the CRM',
            'move_crm_stage'     => 'Move the deal to another stage',
            'notify_team'        => 'Notify your team',
            'send_message'       => 'Send the customer a message',
            'call_webhook'       => 'Send the details to another system',
            'write_google_sheet' => 'Add a row to your Google Sheet',
            'create_task'        => 'Create a follow-up task for your team',
            'start_sequence'     => 'Start a sequence for the customer',
        );
    }

    protected function describe_action(array $a, array $data)
    {
        switch ($a['type']) {
            case 'add_label':          return 'Tag as "' . $this->param($a, 'label', $data) . '"';
            case 'update_lead_score':  return 'Lead score ' . (((int) $this->param($a, 'delta', $data)) >= 0 ? '+' : '') . (int) $this->param($a, 'delta', $data);
            case 'create_crm_deal':    return 'Create deal "' . $this->param($a, 'title', $data) . '"';
            case 'move_crm_stage':     return 'Move to stage "' . $this->param($a, 'stage', $data) . '"';
            case 'notify_team':        return 'Notify ' . $this->param($a, 'to', $data) . ' (' . $this->param($a, 'channel', $data) . ')';
            case 'send_message':       return 'Message: ' . mb_substr((string) $this->param($a, 'text', $data), 0, 80);
            case 'call_webhook':       return 'POST to ' . $this->param($a, 'url', $data);
            case 'write_google_sheet': return 'Append a row to the connected sheet';
            case 'create_task':        return 'Task: ' . $this->param($a, 'subject', $data);
            case 'start_sequence':     return 'Start sequence #' . $this->param($a, 'sequence_id', $data);
        }
        return 'Unknown action "' . $a['type'] . '"';
    }

    /**
     * Action parameters may reference event fields with {{field}}, so one rule can say
     * "Hello {{full_name}}" without a template engine. Unknown fields render empty.
     */
    public function param(array $action, $key, array $data, $default = '')
    {
        if (!isset($action[$key])) return $default;
        $v = $action[$key];
        if (!is_string($v)) return $v;
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($data) {
            $val = self::resolve_field($data, $m[1]);
            if (is_array($val)) return '';
            return $val === null ? '' : (string) $val;
        }, $v);
    }

    // =====================================================================
    // Execution
    // =====================================================================

    /**
     * Run every active rule of the event's tenant whose trigger is this event type.
     * @return array counters, used for the worker's ops-health detail line
     */
    public function process_event(array $event)
    {
        $user_id = (int) $event['user_id'];
        $out = array('rules' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0);
        $rules = $this->gw->active_rules($user_id, (string) $event['event_type']);
        $out['rules'] = count($rules);
        foreach ($rules as $rule) {
            $r = $this->run_rule($rule, $event);
            $k = $r['status'];
            if (!isset($out[$k])) $out[$k] = 0;
            $out[$k]++;
        }
        return $out;
    }

    /**
     * One rule against one event. Claims the (rule, event) pair first, so a replay of the
     * same event can never run the actions twice.
     *
     * @return array{status:string, run_id:int, detail:string, actions:array}
     */
    public function run_rule(array $rule, array $event)
    {
        $rule_id = (int) $rule['id'];
        $event_id = (int) (isset($event['id']) ? $event['id'] : 0);

        // TENANT ISOLATION — hard stop, not a filter. A rule must never observe another
        // tenant's event, whatever the caller passed in.
        if ((int) $rule['user_id'] !== (int) $event['user_id']) {
            $this->gw->log('error', "automation: rule {$rule_id} (user {$rule['user_id']}) refused event {$event_id} of user {$event['user_id']}");
            return array('status' => self::ST_SKIPPED, 'run_id' => 0, 'detail' => 'tenant_mismatch', 'actions' => array());
        }

        if ((string) $rule['status'] !== 'active') {
            return array('status' => self::ST_SKIPPED, 'run_id' => 0, 'detail' => 'rule_not_active', 'actions' => array());
        }

        $run_id = (int) $this->gw->run_claim($rule_id, $event_id);
        if ($run_id <= 0) {
            return array('status' => 'duplicate', 'run_id' => 0, 'detail' => 'already ran for this event', 'actions' => array());
        }

        $data = $this->evaluation_data($event);
        $verdict = $this->conditions_match(isset($rule['conditions_json']) ? $rule['conditions_json'] : array(), $data);
        if (!$verdict['matched']) {
            $first = $verdict['failed'][0];
            $detail = 'conditions not met: ' . $first['field'] . ' ' . $first['op'] . ' ' . $this->scalar($first['value'])
                    . ' (actual: ' . $this->scalar($first['actual']) . ')';
            $this->gw->run_finish($run_id, self::ST_SKIPPED, $detail);
            return array('status' => self::ST_SKIPPED, 'run_id' => $run_id, 'detail' => $detail, 'actions' => array());
        }

        $results = array();
        $failed = 0; $ran = 0;
        foreach (self::normalise_actions(isset($rule['actions_json']) ? $rule['actions_json'] : array()) as $i => $action) {
            $res = $this->execute_action($i, $action, $data, $event, $rule);
            $this->gw->action_log($run_id, $i, (string) $action['type'], $res['status'], $res['error'], $res['retry_count']);
            $results[] = $res + array('index' => $i, 'type' => $action['type']);
            if ($res['status'] === self::ST_FAILED) $failed++;
            if ($res['status'] === self::ST_SUCCESS) $ran++;
        }

        $status = $failed > 0 ? self::ST_FAILED : ($ran > 0 ? self::ST_SUCCESS : self::ST_SKIPPED);
        $detail = count($results) . ' action(s): ' . $ran . ' done, ' . $failed . ' failed, '
                . (count($results) - $ran - $failed) . ' skipped';
        $this->gw->run_finish($run_id, $status, $detail);
        return array('status' => $status, 'run_id' => $run_id, 'detail' => $detail, 'actions' => $results);
    }

    protected function scalar($v)
    {
        if ($v === null) return 'null';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        return (string) $v;
    }

    /**
     * Run one action, retrying only the failures the gateway marked retryable.
     * @return array{status:string, error:?string, retry_count:int}
     */
    public function execute_action($index, array $action, array $data, array $event, array $rule)
    {
        $attempt = 0;
        $last_error = null;
        while (true) {
            $attempt++;
            list($status, $error, $retryable) = $this->dispatch($action, $data, $event, $rule);
            if ($status !== self::ST_FAILED || !$retryable || $attempt >= self::MAX_ATTEMPTS) {
                return array('status' => $status, 'error' => $error, 'retry_count' => $attempt - 1);
            }
            $last_error = $error;
            $this->gw->log('error', 'automation action ' . $action['type'] . ' attempt ' . $attempt . ' failed: ' . $error);
            $wait = isset(self::$backoff[$attempt - 1]) ? self::$backoff[$attempt - 1] : end(self::$backoff);
            $this->gw->sleep_backoff($wait);
        }
        // unreachable
    }

    /**
     * @return array{0:string status, 1:?string error, 2:bool retryable}
     */
    protected function dispatch(array $action, array $data, array $event, array $rule)
    {
        $user_id = (int) $rule['user_id'];
        $type = (string) $action['type'];
        $subscriber_id = (int) self::resolve_field($data, 'subscriber_id');
        if ($subscriber_id <= 0 && (string) self::resolve_field($data, 'subject_type') === 'subscriber') {
            $subscriber_id = (int) self::resolve_field($data, 'subject_id');
        }

        try {
            switch ($type) {
                case 'add_label':
                    $label = trim((string) $this->param($action, 'label', $data));
                    if ($label === '') return array(self::ST_FAILED, 'no label given', false);
                    if ($subscriber_id <= 0) return array(self::ST_SKIPPED, 'no customer on this event', false);
                    return $this->norm($this->gw->add_label($user_id, $subscriber_id, $label));

                case 'update_lead_score':
                    $delta = (int) $this->param($action, 'delta', $data, 0);
                    if ($delta === 0) return array(self::ST_SKIPPED, 'score change is zero', false);
                    if ($subscriber_id <= 0) return array(self::ST_SKIPPED, 'no customer on this event', false);
                    return $this->norm($this->gw->update_lead_score($user_id, $subscriber_id, $delta));

                case 'create_crm_deal':
                    return $this->norm($this->gw->create_crm_deal($user_id, $subscriber_id, array(
                        'title'        => (string) $this->param($action, 'title', $data, 'Automation deal'),
                        'value'        => (float) $this->param($action, 'value', $data, 0),
                        'currency'     => (string) $this->param($action, 'currency', $data, 'EGP'),
                        'stage'        => (string) $this->param($action, 'stage', $data, ''),
                        'contact_name' => (string) $this->param($action, 'contact_name', $data, (string) self::resolve_field($data, 'full_name')),
                        'source'       => 'automation',
                    )));

                case 'move_crm_stage':
                    $stage = trim((string) $this->param($action, 'stage', $data));
                    if ($stage === '') return array(self::ST_FAILED, 'no stage given', false);
                    $deal_id = (int) $this->param($action, 'deal_id', $data, (int) self::resolve_field($data, 'deal.id'));
                    return $this->norm($this->gw->move_crm_stage($user_id, $deal_id, $subscriber_id, $stage));

                case 'notify_team':
                    $to = trim((string) $this->param($action, 'to', $data));
                    $text = trim((string) $this->param($action, 'text', $data));
                    if ($to === '' || $text === '') return array(self::ST_FAILED, 'notify_team needs a recipient and a message', false);
                    $channel = (string) $this->param($action, 'channel', $data, 'whatsapp');
                    return $this->norm($this->gw->notify_team($user_id, array(
                        'channel' => $channel === 'email' ? 'email' : 'whatsapp',
                        'to'      => $to,
                        'subject' => (string) $this->param($action, 'subject', $data, 'MonkeyBot automation'),
                        'text'    => $text,
                    )));

                case 'send_message':
                    $text = trim((string) $this->param($action, 'text', $data));
                    if ($text === '') return array(self::ST_FAILED, 'no message text', false);
                    $decision = $this->policy_for($action, $data);
                    if (empty($decision['allowed'])) {
                        // brief 26: the policy layer decides, and a denial is final. Never send,
                        // never retry — retrying cannot change a messaging-window verdict.
                        return array(self::ST_POLICY, $decision['reason'], false);
                    }
                    $recipient = (string) $this->param($action, 'to', $data, (string) self::resolve_field($data, 'subscribe_id'));
                    if ($recipient === '') $recipient = (string) self::resolve_field($data, 'subscriber.subscribe_id');
                    if ($recipient === '') return array(self::ST_SKIPPED, 'no recipient on this event', false);
                    return $this->norm($this->gw->send_text(
                        $user_id,
                        $this->platform_for($action, $data),
                        $recipient,
                        $text,
                        (string) $this->param($action, 'page_id', $data, (string) self::resolve_field($data, 'page_id'))
                    ));

                case 'call_webhook':
                    $url = trim((string) $this->param($action, 'url', $data));
                    if (stripos($url, 'https://') !== 0) {
                        return array(self::ST_FAILED, 'webhook URL must start with https://', false);
                    }
                    return $this->norm($this->gw->call_webhook($url, array(
                        'event'   => array(
                            'id'             => isset($event['id']) ? (int) $event['id'] : 0,
                            'type'           => isset($event['event_type']) ? $event['event_type'] : '',
                            'subject_type'   => isset($event['subject_type']) ? $event['subject_type'] : '',
                            'subject_id'     => isset($event['subject_id']) ? $event['subject_id'] : '',
                            'correlation_id' => isset($event['correlation_id']) ? $event['correlation_id'] : null,
                            'created_at'     => isset($event['created_at']) ? $event['created_at'] : null,
                        ),
                        'rule'    => array('id' => (int) $rule['id'], 'name' => isset($rule['name']) ? $rule['name'] : ''),
                        'data'    => $data,
                    )));

                case 'write_google_sheet':
                    return $this->norm($this->gw->write_google_sheet($user_id, array(
                        'name'  => (string) $this->param($action, 'name', $data, (string) self::resolve_field($data, 'full_name')),
                        'phone' => (string) $this->param($action, 'phone', $data, (string) self::resolve_field($data, 'phone')),
                        'email' => (string) $this->param($action, 'email', $data, (string) self::resolve_field($data, 'email')),
                        'note'  => (string) $this->param($action, 'note', $data, ''),
                        'event' => isset($event['event_type']) ? $event['event_type'] : '',
                    )));

                case 'create_task':
                    $deal_id = (int) self::resolve_field($data, 'deal.id');
                    if ($subscriber_id <= 0 && $deal_id <= 0) return array(self::ST_SKIPPED, 'no customer or deal on this event', false);
                    return $this->norm($this->gw->create_task($user_id, $subscriber_id, $deal_id, array(
                        'subject'        => (string) $this->param($action, 'subject', $data, 'Follow up'),
                        'description'    => (string) $this->param($action, 'description', $data, ''),
                        'due_in_minutes' => (int) $this->param($action, 'due_in_minutes', $data, 0),
                    )));

                case 'start_sequence':
                    $sequence_id = (int) $this->param($action, 'sequence_id', $data, 0);
                    if ($sequence_id <= 0) return array(self::ST_FAILED, 'no sequence chosen', false);
                    if ($subscriber_id <= 0) return array(self::ST_SKIPPED, 'no customer on this event', false);
                    return $this->norm($this->gw->start_sequence($user_id, $sequence_id, $subscriber_id));
            }
        } catch (Throwable $e) {
            // An action that blows up must not abort the run or the worker (brief 51).
            return array(self::ST_FAILED, get_class($e) . ': ' . $e->getMessage(), false);
        }

        return array(self::ST_FAILED, 'unknown action type: ' . $type, false);
    }

    /** Gateways return array(ok, error, retryable); turn that into an action status triple. */
    protected function norm($r)
    {
        $ok = is_array($r) ? !empty($r[0]) : (bool) $r;
        $err = is_array($r) && isset($r[1]) ? $r[1] : null;
        $retryable = is_array($r) && !empty($r[2]);
        if ($ok) return array(self::ST_SUCCESS, null, false);
        return array(self::ST_FAILED, $err === null || $err === '' ? 'action failed' : (string) $err, $retryable);
    }

    protected function platform_for(array $action, array $data)
    {
        $p = trim((string) $this->param($action, 'platform', $data));
        if ($p === '') $p = (string) self::resolve_field($data, 'platform');
        if ($p === '') $p = (string) self::resolve_field($data, 'subscriber.platform');
        return $p;
    }

    /**
     * The channel policy verdict for a send_message action. Always sender='automation' —
     * an automation is never a human agent, so it can never use the HUMAN_AGENT tag.
     */
    public function policy_for(array $action, array $data)
    {
        return $this->gw->policy_decide($this->platform_for($action, $data), array(
            'sender'          => 'automation',
            'last_inbound_at' => self::resolve_field($data, 'last_inbound_at'),
            'opted_out'       => (bool) self::resolve_field($data, 'opted_out'),
        ));
    }
}
