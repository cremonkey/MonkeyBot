<?php
require_once("Home.php");

/**
 * Sequences (Phase 10.5, brief §25 + §46). The owner-facing side of a named sequence of
 * timed steps per enrolled subscriber: "10 minutes after this starts, send X. 6 hours
 * later, send Y. A day later, create a task for the team."
 *
 * Nothing here executes a step. Saving a sequence writes sequence_steps rows; the worker
 * (php index.php sequence_worker run) advances enrollments (application/helpers/
 * sequence_helper.php: sequence_advance()), which runs each step through
 * Automation_engine::execute_action() — the SAME dispatcher an Automation Rule uses, so
 * channel policy and every action implementation are shared, not re-implemented.
 *
 * Follows the Automations.php pattern: entitlement_require() in the constructor,
 * ajax_check() + csrf_token_check() on every POST, every query scoped to $this->uid.
 */
class Sequences extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper(array('entitlement', 'sequence', 'event', 'ops_health'));
        entitlement_require('sequences');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->load->library('automation_engine');
    }

    /** Single source of truth for the "workers off" banner + JSON field (task 6 / brief §51). */
    protected function workers_status()
    {
        return automation_workers_status($this->config->item('automation_engine_enabled') === true);
    }

    // ------------------------------------------------------------------ pages

    public function index()
    {
        $sequences = $this->db->from('sequences')->where('user_id', $this->uid)->order_by('id', 'DESC')->get()->result_array();

        foreach ($sequences as $i => $s) {
            $sequences[$i]['step_count'] = $this->db->from('sequence_steps')->where('sequence_id', (int) $s['id'])->count_all_results();
            $counts = $this->db->select('status, COUNT(*) AS n')->from('sequence_enrollments')
                ->where('sequence_id', (int) $s['id'])->group_by('status')->get()->result_array();
            $tally = array('active' => 0, 'completed' => 0, 'stopped' => 0, 'failed' => 0);
            foreach ($counts as $c) $tally[$c['status']] = (int) $c['n'];
            $sequences[$i]['tally'] = $tally;
        }

        $data['sequences'] = $sequences;
        $data['engine_on'] = $this->config->item('automation_engine_enabled') === true;
        $data['workers_status'] = $this->workers_status();
        $data['page_title'] = 'Sequences';
        $data['body'] = 'admin/sequences/index';
        $this->_viewcontroller($data);
    }

    public function form($id = 0)
    {
        $sequence = null;
        $steps = array();
        if ((int) $id > 0) {
            $sequence = $this->owned_sequence((int) $id);
            if (!$sequence) { show_404(); return; }
            $steps = $this->db->from('sequence_steps')->where('sequence_id', (int) $id)->order_by('position', 'ASC')->get()->result_array();
        }
        $data['sequence'] = $sequence;
        $data['steps'] = $steps;
        $data['triggers'] = array_merge(array('manual' => 'Only when I add someone myself'), event_catalogue());
        $data['action_types'] = sequence_action_catalogue();
        $data['operators'] = Automation_engine::operators();
        $data['page_title'] = $sequence ? 'Edit sequence' : 'New sequence';
        $data['body'] = 'admin/sequences/form';
        $this->_viewcontroller($data);
    }

    public function enrollments($sequence_id = 0)
    {
        $sequence = null;
        if ((int) $sequence_id > 0) {
            $sequence = $this->owned_sequence((int) $sequence_id);
            if (!$sequence) { show_404(); return; }
        }

        $this->db->select('e.*, s.name AS sequence_name')->from('sequence_enrollments e')
            ->join('sequences s', 's.id = e.sequence_id', 'inner')->where('s.user_id', $this->uid);
        if ($sequence) $this->db->where('e.sequence_id', (int) $sequence_id);
        $rows = $this->db->order_by('e.id', 'DESC')->limit(300)->get()->result_array();

        // names, best effort — an enrollment must still be readable if the subscriber was deleted
        foreach ($rows as $i => $r) {
            $sub = $this->db->select('full_name')->from('messenger_bot_subscriber')
                ->where('id', (int) $r['subscriber_id'])->where('user_id', $this->uid)->limit(1)->get()->row_array();
            $rows[$i]['customer_name'] = $sub ? $sub['full_name'] : '(customer no longer found)';
        }

        // Step-outcome counts, including the safety-gate skip reasons (2026-09-27) — brief
        // §51: no silent failure. An owner must be able to see "12 skipped: quiet hours"
        // without reading sequence_step_log by hand.
        $this->db->select('l.status, COUNT(*) AS n')->from('sequence_step_log l')
            ->join('sequence_enrollments e', 'e.id = l.enrollment_id', 'inner')
            ->join('sequences s2', 's2.id = e.sequence_id', 'inner')
            ->where('s2.user_id', $this->uid);
        if ($sequence) $this->db->where('e.sequence_id', (int) $sequence_id);
        $log_counts = $this->db->group_by('l.status')->get()->result_array();
        $step_log_tally = array();
        foreach ($log_counts as $c) $step_log_tally[$c['status']] = (int) $c['n'];

        $data['enrollments'] = $rows;
        $data['sequence'] = $sequence;
        $data['step_log_tally'] = $step_log_tally;
        $data['page_title'] = $sequence ? 'Enrollments — ' . $sequence['name'] : 'All enrollments';
        $data['body'] = 'admin/sequences/enrollments';
        $this->_viewcontroller($data);
    }

    // ------------------------------------------------------------------ ajax

    /** Create or update a sequence and its steps in one call (steps are replaced wholesale). */
    public function save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $id = (int) $this->input->post('id');
        $name = trim(strip_tags((string) $this->input->post('name', true)));
        $trigger = trim((string) $this->input->post('trigger_event', true));
        if ($trigger === '') $trigger = 'manual';
        $enroll_conditions = Automation_engine::normalise_conditions((string) $this->input->post('enroll_conditions_json'));
        $status = (string) $this->input->post('status', true);
        if (!in_array($status, array('draft', 'active', 'paused'), true)) $status = 'draft';

        $steps = json_decode((string) $this->input->post('steps_json'), true);
        if (!is_array($steps)) $steps = array();

        $errors = $this->validate($name, $trigger, $steps);
        if (!empty($errors)) { echo json_encode(array('status' => '0', 'errors' => $errors)); return; }

        $now = date('Y-m-d H:i:s');
        $row = array(
            'name' => $name, 'trigger_event' => $trigger,
            'enroll_conditions_json' => json_encode(array_values($enroll_conditions), JSON_UNESCAPED_UNICODE),
            'status' => $status, 'updated_at' => $now,
        );

        if ($id > 0) {
            if (!$this->owned_sequence($id)) { echo json_encode(array('status' => '0', 'errors' => array('Sequence not found.'))); return; }
            $this->db->where('id', $id)->where('user_id', $this->uid)->update('sequences', $row);
        } else {
            $row['user_id'] = $this->uid;
            $row['created_at'] = $now;
            $this->db->insert('sequences', $row);
            $id = (int) $this->db->insert_id();
        }

        // replace the step list wholesale — simplest model that cannot desync position order
        $this->db->where('sequence_id', $id)->delete('sequence_steps');
        foreach (array_values($steps) as $i => $s) {
            $this->db->insert('sequence_steps', array(
                'sequence_id' => $id, 'position' => $i,
                'delay_seconds' => max(0, (int) (isset($s['delay_seconds']) ? $s['delay_seconds'] : 0)),
                'action_type' => mb_substr((string) (isset($s['action_type']) ? $s['action_type'] : ''), 0, 48),
                'action_params_json' => json_encode(is_array(isset($s['action_params']) ? $s['action_params'] : null) ? $s['action_params'] : array(), JSON_UNESCAPED_UNICODE),
                'stop_condition_json' => !empty($s['stop_condition']) ? json_encode(array_values(Automation_engine::normalise_conditions($s['stop_condition'])), JSON_UNESCAPED_UNICODE) : null,
                'created_at' => $now,
            ));
        }

        $this->load->helper('audit'); audit_record($this->uid, 'sequence.saved', 'sequence', $id, null, array('status'=>$status));
        $ws = $this->workers_status();
        echo json_encode(array(
            'status' => '1', 'id' => $id, 'message' => 'Sequence saved.',
            'workers_paused' => $ws['workers_paused'],
        ) + ($ws['workers_paused'] ? array('workers_message' => $ws['message_en']) : array()), JSON_UNESCAPED_UNICODE);
    }

    /** Activate / pause / draft. Does not touch already-running enrollments' current step. */
    public function set_status()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id');
        $status = (string) $this->input->post('status', true);
        if (!in_array($status, array('draft', 'active', 'paused'), true)) { echo json_encode(array('status' => '0', 'message' => 'Unknown status.')); return; }
        $seq = $this->owned_sequence($id);
        if (!$seq) { echo json_encode(array('status' => '0', 'message' => 'Sequence not found.')); return; }

        if ($status === 'active') {
            $steps = $this->db->from('sequence_steps')->where('sequence_id', $id)->get()->result_array();
            $errors = $this->validate($seq['name'], $seq['trigger_event'], array_map(function ($s) {
                return array('delay_seconds' => $s['delay_seconds'], 'action_type' => $s['action_type']);
            }, $steps));
            if (!empty($errors)) { echo json_encode(array('status' => '0', 'message' => implode(' ', $errors))); return; }
        }

        $this->db->where('id', $id)->where('user_id', $this->uid)
            ->update('sequences', array('status' => $status, 'updated_at' => date('Y-m-d H:i:s')));
        $this->load->helper('audit'); audit_record($this->uid, $status === 'active' ? 'sequence.published' : 'sequence.disabled', 'sequence', $id, array('status'=>$seq['status']), array('status'=>$status));
        $ws = $this->workers_status();
        echo json_encode(array(
            'status' => '1', 'new_status' => $status,
            'workers_paused' => $ws['workers_paused'],
        ) + ($ws['workers_paused'] ? array('workers_message' => $ws['message_en']) : array()));
    }

    public function delete_sequence()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id');
        if (!$this->owned_sequence($id)) { echo json_encode(array('status' => '0')); return; }
        $this->db->where('id', $id)->where('user_id', $this->uid)->delete('sequences');
        $this->db->where('sequence_id', $id)->delete('sequence_steps');
        log_message('error', 'audit: sequence ' . $id . ' deleted by user ' . $this->session->userdata('user_id'));
        echo json_encode(array('status' => '1'));
    }

    /** Owner-language search of this tenant's customers, for the manual-enroll picker. */
    public function search_subscribers()
    {
        $this->ajax_check();
        header('Content-Type: application/json');
        $q = trim((string) $this->input->get('q', true));
        if ($q === '') { echo json_encode(array('status' => '1', 'rows' => array())); return; }
        $rows = $this->db->select('id, full_name, phone_number, social_media')->from('messenger_bot_subscriber')
            ->where('user_id', $this->uid)
            ->group_start()->like('full_name', $q)->or_like('phone_number', $q)->or_like('subscribe_id', $q)->group_end()
            ->order_by('id', 'DESC')->limit(20)->get()->result_array();
        echo json_encode(array('status' => '1', 'rows' => $rows), JSON_UNESCAPED_UNICODE);
    }

    /** Manual "enroll a subscriber" action (brief item 5). Idempotent: see sequence_enroll(). */
    public function enroll_manual()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $id = (int) $this->input->post('id');
        $subscriber_id = (int) $this->input->post('subscriber_id');
        $seq = $this->owned_sequence($id);
        if (!$seq) { echo json_encode(array('status' => '0', 'message' => 'Sequence not found.')); return; }

        $sub = $this->db->select('id, subscribe_id, page_table_id, social_media')->from('messenger_bot_subscriber')
            ->where('id', $subscriber_id)->where('user_id', $this->uid)->limit(1)->get()->row_array();
        if (!$sub) { echo json_encode(array('status' => '0', 'message' => 'Customer not found.')); return; }

        list($ok, $note, $enrollment_id) = sequence_enroll(
            $this->uid, $id, (int) $sub['id'], (string) $sub['social_media'], (string) $sub['subscribe_id'], (int) $sub['page_table_id']
        );
        if (!$ok) { echo json_encode(array('status' => '0', 'message' => $note ?: 'Could not enroll this customer.')); return; }
        log_message('error', 'audit: sequence ' . $id . ' manually enrolled subscriber ' . $subscriber_id . ' by user ' . $this->session->userdata('user_id'));
        $ws = $this->workers_status();
        echo json_encode(array(
            'status' => '1', 'enrollment_id' => $enrollment_id, 'note' => $note,
            'workers_paused' => $ws['workers_paused'],
        ) + ($ws['workers_paused'] ? array('workers_message' => $ws['message_en']) : array()), JSON_UNESCAPED_UNICODE);
    }

    /**
     * DRY RUN. The schedule this sequence WOULD produce, and — with an optional sample
     * customer — the channel policy verdict for any send_message step right now. Enrolls
     * nobody, writes nothing.
     */
    public function test()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $steps = json_decode((string) $this->input->post('steps_json'), true);
        if (!is_array($steps)) $steps = array();
        $sample_id = (int) $this->input->post('sample_subscriber_id');
        if ($sample_id > 0) {
            $owned = $this->db->from('messenger_bot_subscriber')->where('id', $sample_id)->where('user_id', $this->uid)->count_all_results();
            if (!$owned) $sample_id = 0;   // tenant-scoped: never preview against another account's customer
        }

        $schedule = sequence_dry_run($steps, $this->uid, $sample_id);
        foreach ($schedule as $i => $row) {
            if (isset($row['policy']['reason'])) $schedule[$i]['policy']['explain'] = channel_policy_explain($row['policy']['reason']);
        }
        echo json_encode(array('status' => '1', 'schedule' => $schedule), JSON_UNESCAPED_UNICODE);
    }

    // ----------------------------------------------------------------- helpers

    protected function owned_sequence($id)
    {
        if ((int) $id <= 0) return null;
        return $this->db->from('sequences')->where('id', (int) $id)->where('user_id', $this->uid)->limit(1)->get()->row_array();
    }

    /** Owner-language validation — everything that would make a sequence do nothing is an error. */
    protected function validate($name, $trigger, array $steps)
    {
        $errors = array();
        if ($name === '') $errors[] = 'Give the sequence a name so you can recognise it later.';
        if ($trigger !== 'manual' && !array_key_exists($trigger, event_catalogue())) $errors[] = 'Choose what starts this sequence.';
        if (empty($steps)) $errors[] = 'Add at least one step.';

        $known = sequence_action_catalogue();
        foreach ($steps as $i => $s) {
            $n = $i + 1;
            $type = isset($s['action_type']) ? $s['action_type'] : '';
            if (!array_key_exists($type, $known)) { $errors[] = "Step {$n}: choose what this step should do."; continue; }
            $params = isset($s['action_params']) && is_array($s['action_params']) ? $s['action_params'] : array();
            switch ($type) {
                case 'send_message':
                    if (trim((string) ($params['text'] ?? '')) === '') $errors[] = "Step {$n}: write the message to send.";
                    break;
                case 'add_label':
                    if (trim((string) ($params['label'] ?? '')) === '') $errors[] = "Step {$n}: type the tag to add.";
                    break;
                case 'create_task':
                    if (trim((string) ($params['subject'] ?? '')) === '') $errors[] = "Step {$n}: give the task a title.";
                    break;
                case 'notify_team':
                    if (trim((string) ($params['to'] ?? '')) === '') $errors[] = "Step {$n}: who should be notified?";
                    if (trim((string) ($params['text'] ?? '')) === '') $errors[] = "Step {$n}: write the notification message.";
                    break;
                case 'call_webhook':
                    if (stripos(trim((string) ($params['url'] ?? '')), 'https://') !== 0) $errors[] = "Step {$n}: the address must start with https:// .";
                    break;
                case 'start_sequence':
                    if ((int) ($params['sequence_id'] ?? 0) <= 0) $errors[] = "Step {$n}: choose which sequence to start.";
                    break;
            }
        }
        return $errors;
    }
}
