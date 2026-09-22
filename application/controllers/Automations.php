<?php
require_once("Home.php");

/**
 * Automations (Phase 10.5, brief 23 + 46).
 *
 * The owner-facing side of the Automation Engine: "when THIS happens, if THAT is true, do
 * these things". Everything on screen is in business language — trigger names come from
 * event_catalogue(), operators from Automation_engine::operators(), action names from
 * Automation_engine::action_catalogue(). The words 'event', 'payload' and 'webhook' only
 * appear where the owner genuinely needs them.
 *
 * Nothing here executes an automation. Saving a rule writes a row; the worker
 * (php index.php automation_worker run) is what acts on events. The "Test" button runs the
 * engine's dry_run(), which evaluates the real conditions and reports which actions WOULD
 * run — and, for a message, what the channel policy would say — without doing any of it.
 *
 * Follows the Pricing.php pattern: entitlement_require() in the constructor, ajax_check() +
 * csrf_token_check() on every POST, every query scoped to $this->uid.
 */
class Automations extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper('entitlement');
        entitlement_require('automations');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->load->helper('event');
        $this->load->library('automation_engine');
    }

    // ------------------------------------------------------------------ pages

    public function index()
    {
        $rules = $this->db->from('automation_rules')->where('user_id', $this->uid)->order_by('id', 'DESC')->get()->result_array();

        // last run + a 30-day tally per rule, so the list answers "is this thing working?"
        foreach ($rules as $i => $r) {
            $rules[$i]['last_run'] = $this->db->from('automation_runs')->where('rule_id', (int) $r['id'])
                ->order_by('id', 'DESC')->limit(1)->get()->row_array();
            $counts = $this->db->select('status, COUNT(*) AS n')->from('automation_runs')
                ->where('rule_id', (int) $r['id'])->where('created_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))
                ->group_by('status')->get()->result_array();
            $tally = array('success' => 0, 'failed' => 0, 'skipped' => 0);
            foreach ($counts as $c) $tally[$c['status']] = (int) $c['n'];
            $rules[$i]['tally'] = $tally;
            $rules[$i]['action_count'] = count(Automation_engine::normalise_actions($r['actions_json']));
        }

        $data['rules'] = $rules;
        $data['engine_on'] = $this->config->item('automation_engine_enabled') === true;
        $data['events_waiting'] = $this->db->from('events')->where('user_id', $this->uid)
            ->where('processed_at IS NULL', null, false)->count_all_results();
        $data['page_title'] = 'Automations';
        $data['body'] = 'admin/automations/index';
        $this->_viewcontroller($data);
    }

    public function form($id = 0)
    {
        $rule = null;
        if ((int) $id > 0) {
            $rule = $this->owned_rule((int) $id);
            if (!$rule) { show_404(); return; }
        }
        $data['rule'] = $rule;
        $data['triggers'] = event_catalogue();
        $data['operators'] = Automation_engine::operators();
        $data['actions'] = Automation_engine::action_catalogue();
        $data['recent_events'] = $this->db->select('id, event_type, subject_type, subject_id, created_at')
            ->from('events')->where('user_id', $this->uid)->order_by('id', 'DESC')->limit(25)->get()->result_array();
        $data['page_title'] = $rule ? 'Edit automation' : 'New automation';
        $data['body'] = 'admin/automations/form';
        $this->_viewcontroller($data);
    }

    public function runs($rule_id = 0)
    {
        $rule = null;
        if ((int) $rule_id > 0) {
            $rule = $this->owned_rule((int) $rule_id);
            if (!$rule) { show_404(); return; }
        }

        // join through automation_rules so a run of another tenant can never be listed
        $this->db->select('r.id, r.rule_id, r.event_id, r.status, r.detail, r.created_at, ar.name AS rule_name, e.event_type')
            ->from('automation_runs r')
            ->join('automation_rules ar', 'ar.id = r.rule_id', 'inner')
            ->join('events e', 'e.id = r.event_id', 'left')
            ->where('ar.user_id', $this->uid);
        if ($rule) $this->db->where('r.rule_id', (int) $rule_id);
        $data['runs'] = $this->db->order_by('r.id', 'DESC')->limit(200)->get()->result_array();

        $data['rule'] = $rule;
        $data['page_title'] = $rule ? 'Activity — ' . $rule['name'] : 'Automation activity';
        $data['body'] = 'admin/automations/runs';
        $this->_viewcontroller($data);
    }

    // ------------------------------------------------------------------ ajax

    /** Create or update a rule. */
    public function save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $id = (int) $this->input->post('id');
        $name = trim(strip_tags((string) $this->input->post('name', true)));
        $trigger = trim((string) $this->input->post('trigger_event', true));
        $conditions = Automation_engine::normalise_conditions((string) $this->input->post('conditions_json'));
        $actions = Automation_engine::normalise_actions((string) $this->input->post('actions_json'));
        $status = (string) $this->input->post('status', true);
        if (!in_array($status, array('draft', 'active', 'paused'), true)) $status = 'draft';

        $errors = $this->validate($name, $trigger, $conditions, $actions);
        if (!empty($errors)) { echo json_encode(array('status' => '0', 'errors' => $errors)); return; }

        $now = date('Y-m-d H:i:s');
        $row = array(
            'name' => $name, 'trigger_event' => $trigger,
            'conditions_json' => json_encode(array_values($conditions), JSON_UNESCAPED_UNICODE),
            'actions_json' => json_encode(array_values($actions), JSON_UNESCAPED_UNICODE),
            'status' => $status, 'updated_at' => $now,
        );

        if ($id > 0) {
            if (!$this->owned_rule($id)) { echo json_encode(array('status' => '0', 'errors' => array('Automation not found.'))); return; }
            $this->db->where('id', $id)->where('user_id', $this->uid)->update('automation_rules', $row);
        } else {
            $row['user_id'] = $this->uid;
            $row['created_by'] = (int) $this->session->userdata('user_id');
            $row['created_at'] = $now;
            $this->db->insert('automation_rules', $row);
            $id = (int) $this->db->insert_id();
        }
        log_message('error', 'audit: automation ' . $id . ' saved (' . $status . ') by user ' . $this->session->userdata('user_id'));
        echo json_encode(array('status' => '1', 'id' => $id, 'message' => 'Automation saved.'), JSON_UNESCAPED_UNICODE);
    }

    /** Activate / pause. Separate from save so the list page can toggle without a full form. */
    public function set_status()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id');
        $status = (string) $this->input->post('status', true);
        if (!in_array($status, array('draft', 'active', 'paused'), true)) { echo json_encode(array('status' => '0', 'message' => 'Unknown status.')); return; }
        $rule = $this->owned_rule($id);
        if (!$rule) { echo json_encode(array('status' => '0', 'message' => 'Automation not found.')); return; }

        if ($status === 'active') {
            $errors = $this->validate($rule['name'], $rule['trigger_event'],
                Automation_engine::normalise_conditions($rule['conditions_json']),
                Automation_engine::normalise_actions($rule['actions_json']));
            if (!empty($errors)) { echo json_encode(array('status' => '0', 'message' => implode(' ', $errors))); return; }
        }

        $this->db->where('id', $id)->where('user_id', $this->uid)
            ->update('automation_rules', array('status' => $status, 'updated_at' => date('Y-m-d H:i:s')));
        log_message('error', 'audit: automation ' . $id . ' set to ' . $status . ' by user ' . $this->session->userdata('user_id'));
        echo json_encode(array('status' => '1', 'new_status' => $status));
    }

    public function delete_rule()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id');
        if (!$this->owned_rule($id)) { echo json_encode(array('status' => '0')); return; }
        $this->db->where('id', $id)->where('user_id', $this->uid)->delete('automation_rules');
        log_message('error', 'audit: automation ' . $id . ' deleted by user ' . $this->session->userdata('user_id'));
        echo json_encode(array('status' => '1'));
    }

    /**
     * DRY RUN. Evaluates the (unsaved) rule in the form against a sample event and answers
     * "would this fire, and what would it do?". Executes nothing, writes nothing.
     * Sample event: either one of this tenant's real events by id, or a pasted payload.
     */
    public function test()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $trigger = trim((string) $this->input->post('trigger_event', true));
        $rule = array(
            'id' => 0, 'user_id' => $this->uid, 'status' => 'active', 'name' => 'draft',
            'trigger_event' => $trigger,
            'conditions_json' => (string) $this->input->post('conditions_json'),
            'actions_json' => (string) $this->input->post('actions_json'),
        );

        $event_id = (int) $this->input->post('event_id');
        if ($event_id > 0) {
            // tenant-scoped: a rule can only ever be tested against its own account's events
            $event = $this->db->from('events')->where('id', $event_id)->where('user_id', $this->uid)->limit(1)->get()->row_array();
            if (!$event) { echo json_encode(array('status' => '0', 'message' => 'That example was not found in your account.')); return; }
        } else {
            $payload = json_decode((string) $this->input->post('payload_json'), true);
            if (!is_array($payload)) $payload = array();
            $event = array(
                'id' => 0, 'user_id' => $this->uid, 'event_type' => $trigger,
                'subject_type' => (string) $this->input->post('subject_type', true),
                'subject_id' => (string) $this->input->post('subject_id', true),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'correlation_id' => null, 'created_at' => date('Y-m-d H:i:s'),
            );
        }

        if ((string) $event['event_type'] !== $trigger && $trigger !== '') {
            echo json_encode(array('status' => '0', 'message' => 'That example is a different kind of event (' . event_label($event['event_type']) . ').'));
            return;
        }

        $result = $this->automation_engine->dry_run($rule, $event);
        $result['status'] = '1';
        $result['explain'] = array();
        foreach ($result['failed'] as $f) {
            $result['explain'][] = $f['field'] . ' ' . $f['op'] . ' ' . (is_array($f['value']) ? json_encode($f['value']) : $f['value'])
                . ' — actual value: ' . ($f['actual'] === null ? '(nothing)' : (is_array($f['actual']) ? json_encode($f['actual']) : $f['actual']));
        }
        // the policy reason is machine wording; show the owner sentence next to it
        foreach ($result['actions'] as $i => $a) {
            if (isset($a['policy']['reason'])) $result['actions'][$i]['policy']['explain'] = channel_policy_explain($a['policy']['reason']);
        }
        unset($result['data']['subscriber'], $result['data']['deal']);   // keep the response small
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /** The per-action log of one run (why an action was skipped or what it failed with). */
    public function run_detail($run_id = 0)
    {
        $this->ajax_check();
        header('Content-Type: application/json');
        $run = $this->db->select('r.*')->from('automation_runs r')
            ->join('automation_rules ar', 'ar.id = r.rule_id', 'inner')
            ->where('r.id', (int) $run_id)->where('ar.user_id', $this->uid)->limit(1)->get()->row_array();
        if (!$run) { echo json_encode(array('status' => '0')); return; }
        $actions = $this->db->from('automation_action_log')->where('run_id', (int) $run_id)->order_by('id', 'ASC')->get()->result_array();
        echo json_encode(array('status' => '1', 'run' => $run, 'actions' => $actions), JSON_UNESCAPED_UNICODE);
    }

    // ----------------------------------------------------------------- helpers

    protected function owned_rule($id)
    {
        if ((int) $id <= 0) return null;
        return $this->db->from('automation_rules')->where('id', (int) $id)->where('user_id', $this->uid)->limit(1)->get()->row_array();
    }

    /** Owner-language validation. Everything that would make a rule a silent no-op is an error. */
    protected function validate($name, $trigger, array $conditions, array $actions)
    {
        $errors = array();
        if ($name === '') $errors[] = 'Give the automation a name so you can recognise it later.';
        if (!array_key_exists($trigger, event_catalogue())) $errors[] = 'Choose what should start this automation.';
        if (empty($actions)) $errors[] = 'Add at least one thing to do.';

        $ops = Automation_engine::operators();
        foreach ($conditions as $i => $c) {
            $n = $i + 1;
            if (!isset($c['op']) || !array_key_exists($c['op'], $ops)) $errors[] = "Condition {$n}: choose how to compare.";
            if (($c['op'] ?? '') !== 'exists' && (!isset($c['value']) || $c['value'] === '')) $errors[] = "Condition {$n}: fill in the value to compare against.";
        }

        $known = Automation_engine::action_catalogue();
        foreach ($actions as $i => $a) {
            $n = $i + 1;
            if (!array_key_exists($a['type'], $known)) { $errors[] = "Step {$n}: unknown action."; continue; }
            switch ($a['type']) {
                case 'add_label':
                    if (trim((string) ($a['label'] ?? '')) === '') $errors[] = "Step {$n}: type the tag to add.";
                    break;
                case 'update_lead_score':
                    if ((int) ($a['delta'] ?? 0) === 0) $errors[] = "Step {$n}: the score change cannot be zero.";
                    break;
                case 'move_crm_stage':
                    if (trim((string) ($a['stage'] ?? '')) === '') $errors[] = "Step {$n}: choose the stage to move the deal to.";
                    break;
                case 'notify_team':
                    if (trim((string) ($a['to'] ?? '')) === '') $errors[] = "Step {$n}: who should be notified?";
                    if (trim((string) ($a['text'] ?? '')) === '') $errors[] = "Step {$n}: write the notification message.";
                    if (($a['channel'] ?? '') === 'email' && !filter_var(trim((string) ($a['to'] ?? '')), FILTER_VALIDATE_EMAIL)) $errors[] = "Step {$n}: that is not a valid email address.";
                    break;
                case 'send_message':
                    if (trim((string) ($a['text'] ?? '')) === '') $errors[] = "Step {$n}: write the message to send.";
                    break;
                case 'call_webhook':
                    if (stripos(trim((string) ($a['url'] ?? '')), 'https://') !== 0) $errors[] = "Step {$n}: the address must start with https:// .";
                    break;
            }
        }
        return $errors;
    }
}
