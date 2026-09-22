<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Sequences (Phase 10.5 / brief §25). A named sequence of timed steps per enrolled
 | subscriber — the one product concept that unifies Sales_automation follow-ups, Reengage
 | campaigns and Visual Flow Builder drip WITHOUT touching any of those three (brief item 6:
 | they keep running exactly as they do today; see docs/automations/sequences.md for the
 | manual, opt-in migration guide).
 |
 | Built entirely on things that already exist and are already tested:
 |   - identity + sending: the same messenger_bot_subscriber fields Automation_gateway reads
 |     (subscribe_id, page_table_id, social_media) and the same Automation_gateway::send_text()
 |   - policy: Automation_engine::execute_action()'s send_message case, which already calls
 |     channel_policy_decide() with sender='automation' before every send
 |   - action vocabulary: Automation_engine::execute_action() dispatches EVERY step, including
 |     the sequence-only 'create_task'. Nothing here re-implements what an action does.
 |
 | TIMING MODEL. sequence_steps.delay_seconds is an ABSOLUTE offset from
 | sequence_enrollments.enrolled_at — step "T +6 hours" fires 6 hours after enrollment, not 6
 | hours after the previous step. This matches the brief's own example verbatim and means the
 | "test with sample data" schedule preview needs no arithmetic: delay_seconds already IS the
 | T+ label. A worker outage just means a step's next_run_at is in the past when checked next;
 | it still fires once, at the next tick, never twice (see sequence_advance()'s claim).
 |
 | IDEMPOTENCY. Both entry points use the same INSERT-IGNORE-then-check-insert_id() idiom as
 | Automation_gateway::run_claim() (proven in tests/automation/run.php):
 |   - sequence_enroll(): UNIQUE(sequence_id, subscriber_id, platform) — enrolling twice is a
 |     no-op that returns the existing enrollment id.
 |   - sequence_advance(): UNIQUE(enrollment_id, step_id) on sequence_step_log — advancing the
 |     same enrollment past a step it already ran is a no-op ('duplicate').
 */

if (!function_exists('sequence_db')) {
    function sequence_db()
    {
        $CI =& get_instance();
        return $CI->db;
    }
}

if (!function_exists('sequence_action_catalogue')) {
    /** Action types a sequence step may use — identical vocabulary to Automation Rules,
     *  because sequence_advance() executes a step through the exact same dispatcher. */
    function sequence_action_catalogue()
    {
        $CI =& get_instance();
        if (!class_exists('Automation_engine')) $CI->load->library('automation_engine');
        return Automation_engine::action_catalogue();
    }
}

if (!function_exists('sequence_enroll')) {
    /**
     * Enroll one subscriber into one sequence. Idempotent.
     *
     * @param int         $user_id
     * @param int         $sequence_id
     * @param int         $subscriber_id       messenger_bot_subscriber.id
     * @param string      $platform             messenger_bot_subscriber.social_media
     * @param string      $subscribe_id         external id (what send_text sends to)
     * @param int         $channel_account_id   page_table_id, 0 when not applicable
     * @param int|null    $customer_id          customers.id (Phase 9.5), when known
     * @param int|null    $now                  unix time (tests); defaults to time()
     * @param object|null $db                   injectable CI query-builder-alike (tests/sequences/run.php's
     *                                          FakeDb); defaults to sequence_db(), i.e. the real $CI->db
     * @return array{0:bool ok, 1:?string note, 2:int enrollment_id}
     */
    function sequence_enroll($user_id, $sequence_id, $subscriber_id, $platform, $subscribe_id, $channel_account_id = 0, $customer_id = null, $now = null, $db = null)
    {
        $db = $db !== null ? $db : sequence_db();
        $user_id = (int) $user_id;
        $sequence_id = (int) $sequence_id;
        $subscriber_id = (int) $subscriber_id;
        if ($user_id <= 0 || $sequence_id <= 0 || $subscriber_id <= 0) return array(false, 'missing customer or sequence', 0);

        try {
            $seq = $db->from('sequences')->where('id', $sequence_id)->where('user_id', $user_id)->limit(1)->get()->row_array();
            if (empty($seq)) return array(false, 'sequence not found', 0);

            $first_step = $db->from('sequence_steps')->where('sequence_id', $sequence_id)
                ->order_by('position', 'ASC')->limit(1)->get()->row_array();
            if (empty($first_step)) return array(false, 'sequence has no steps yet', 0);

            $now_ts = $now !== null ? (int) $now : time();
            $enrolled_at = date('Y-m-d H:i:s', $now_ts);
            $next_run_at = date('Y-m-d H:i:s', $now_ts + max(0, (int) $first_step['delay_seconds']));

            $db->query(
                'INSERT IGNORE INTO sequence_enrollments
                    (sequence_id, user_id, customer_id, subscriber_id, platform, subscribe_id, channel_account_id,
                     current_step, status, enrolled_at, next_run_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, \'active\', ?, ?, ?)',
                array(
                    $sequence_id, $user_id, $customer_id !== null ? (int) $customer_id : null, $subscriber_id,
                    mb_substr((string) $platform, 0, 16), mb_substr((string) $subscribe_id, 0, 191), (int) $channel_account_id,
                    $enrolled_at, $next_run_at, $enrolled_at,
                )
            );
            $id = (int) $db->insert_id();
            if ($id > 0) return array(true, null, $id);

            // UNIQUE hit: already enrolled. Idempotent, not an error.
            $existing = $db->from('sequence_enrollments')->where('sequence_id', $sequence_id)
                ->where('subscriber_id', $subscriber_id)->where('platform', mb_substr((string) $platform, 0, 16))
                ->limit(1)->get()->row_array();
            return array(true, 'already enrolled', $existing ? (int) $existing['id'] : 0);
        } catch (Throwable $e) {
            log_message('error', 'sequence_enroll: ' . $e->getMessage());
            return array(false, $e->getMessage(), 0);
        }
    }
}

if (!function_exists('sequence_evaluation_data')) {
    /**
     * The flat namespace a sequence step's stop_condition / action params are evaluated
     * against — the SAME shape Automation_engine::evaluation_data() builds for a rule, plus
     * one sequence-only convenience field: replied_since_enrollment (brief §25's "stop if
     * customer replied", expressible as {field:'replied_since_enrollment', op:'eq', value:true}).
     */
    function sequence_evaluation_data($engine, array $enrollment, array $event)
    {
        $data = $engine->evaluation_data($event);
        $data['enrolled_at'] = $enrollment['enrolled_at'];
        $last_inbound = isset($data['subscriber']['last_subscriber_interaction_time']) ? $data['subscriber']['last_subscriber_interaction_time'] : null;
        $data['replied_since_enrollment'] = $last_inbound
            ? (strtotime($last_inbound) > strtotime($enrollment['enrolled_at']))
            : false;
        return $data;
    }
}

if (!function_exists('sequence_advance')) {
    /**
     * Run the next due step of one enrollment: evaluate its stop_condition, run its action
     * through Automation_engine::execute_action() (so channel policy, retries and the send
     * path are IDENTICAL to an Automation Rule's), log it, and schedule (or complete/stop)
     * the enrollment. Idempotent per (enrollment, step) — see file header.
     *
     * @param int|null $enrollment_id
     * @param int|null $now  unix time (tests); defaults to time()
     * @param array|null $deps  injectable dependencies for tests/sequences/run.php:
     *                          'db' (CI query-builder-alike, default sequence_db()) and
     *                          'engine' (an Automation_engine, default a FRESH one built on
     *                          a real Automation_gateway every call) — mirrors
     *                          Automation_engine's own array('gateway' => ...) injection so
     *                          this stays testable with no CodeIgniter bootstrap and no
     *                          database. The default path deliberately builds a new engine
     *                          per call: Automation_engine caches evaluation_data() per
     *                          event id, and this function's synthetic event always uses id
     *                          0, so a caller that reuses one injected $engine across
     *                          several sequence_advance() calls will see the FIRST call's
     *                          context on every later one. Sequence_worker.php relies on
     *                          the default (fresh-per-call) path for exactly this reason.
     * @return array{status:string, detail:string}
     *   status: success | skipped | skipped_policy | skipped_stop_condition | failed |
     *           duplicate | completed
     */
    function sequence_advance($enrollment_id, $now = null, $deps = null)
    {
        $db = (is_array($deps) && isset($deps['db'])) ? $deps['db'] : sequence_db();
        $enrollment_id = (int) $enrollment_id;
        $now_str = date('Y-m-d H:i:s', $now !== null ? (int) $now : time());

        try {
            $enr = $db->from('sequence_enrollments')->where('id', $enrollment_id)->where('status', 'active')->limit(1)->get()->row_array();
            if (empty($enr)) return array('status' => 'skipped', 'detail' => 'enrollment is not active');

            $seq = $db->from('sequences')->where('id', (int) $enr['sequence_id'])->limit(1)->get()->row_array();
            if (empty($seq)) return array('status' => 'failed', 'detail' => 'sequence no longer exists');

            if ($seq['status'] !== 'active') {
                // brief item 5: pausing a sequence blocks advancing, it does not touch
                // already-running enrollments' current step. next_run_at is left as-is so
                // the enrollment resumes on its own once the sequence is turned back on.
                return array('status' => 'skipped', 'detail' => 'sequence is paused');
            }

            $step = $db->from('sequence_steps')->where('sequence_id', (int) $seq['id'])
                ->where('position', (int) $enr['current_step'])->limit(1)->get()->row_array();
            if (empty($step)) {
                $db->where('id', $enrollment_id)->update('sequence_enrollments', array(
                    'status' => 'completed', 'next_run_at' => null, 'updated_at' => $now_str,
                ));
                return array('status' => 'completed', 'detail' => 'all steps done');
            }

            // CLAIM this (enrollment, step) — the idempotency boundary. A concurrent or
            // repeat worker tick that loses this race stops here, having done nothing.
            $db->query(
                'INSERT IGNORE INTO sequence_step_log (enrollment_id, step_id, status, detail, run_at) VALUES (?, ?, ?, ?, ?)',
                array($enrollment_id, (int) $step['id'], 'failed', 'processing', $now_str)
            );
            $log_id = (int) $db->insert_id();
            if ($log_id <= 0) return array('status' => 'duplicate', 'detail' => 'this step already ran for this enrollment');

            if (is_array($deps) && isset($deps['engine'])) {
                $engine = $deps['engine'];
            } else {
                require_once APPPATH . 'libraries/Automation_gateway.php';
                require_once APPPATH . 'libraries/Automation_engine.php';
                $engine = new Automation_engine(array('gateway' => new Automation_gateway()));
            }

            $event = array(
                'id' => 0, 'user_id' => (int) $enr['user_id'], 'event_type' => 'sequence.step',
                'subject_type' => 'subscriber', 'subject_id' => (int) $enr['subscriber_id'],
                'payload' => json_encode(array('subscriber_id' => (int) $enr['subscriber_id'], 'platform' => $enr['platform']), JSON_UNESCAPED_UNICODE),
                'correlation_id' => null, 'created_at' => $now_str,
            );
            $data = sequence_evaluation_data($engine, $enr, $event);

            // ---- stop condition: evaluated BEFORE the action, halts the WHOLE enrollment ----
            if (!empty($step['stop_condition_json'])) {
                $verdict = $engine->conditions_match($step['stop_condition_json'], $data);
                if ($verdict['matched']) {
                    $detail = 'stopped before this step: its stop condition is now true';
                    $db->where('id', $log_id)->update('sequence_step_log', array('status' => 'skipped_stop_condition', 'detail' => $detail));
                    $db->where('id', $enrollment_id)->update('sequence_enrollments', array(
                        'status' => 'stopped', 'stopped_reason' => $detail, 'next_run_at' => null, 'updated_at' => $now_str,
                    ));
                    return array('status' => 'skipped_stop_condition', 'detail' => $detail);
                }
            }

            // ---- run the step's action through the SAME dispatcher an Automation Rule uses ----
            $params = json_decode((string) $step['action_params_json'], true);
            if (!is_array($params)) $params = array();
            $action = array_merge($params, array('type' => (string) $step['action_type']));
            $rule = array('id' => 0, 'user_id' => (int) $enr['user_id'], 'name' => 'sequence:' . $seq['name']);

            $result = $engine->execute_action(0, $action, $data, $event, $rule);
            $status = (string) $result['status'];
            $detail = $result['error'] !== null ? (string) $result['error'] : 'done';
            $db->where('id', $log_id)->update('sequence_step_log', array('status' => $status, 'detail' => $detail));

            // ---- advance (a failed/skipped step does not block the rest of the sequence;
            // the log above is the record that it happened, per brief §51) ----
            $next_position = (int) $enr['current_step'] + 1;
            $next_step = $db->from('sequence_steps')->where('sequence_id', (int) $seq['id'])
                ->where('position', $next_position)->limit(1)->get()->row_array();
            if ($next_step) {
                $enrolled_ts = strtotime($enr['enrolled_at']);
                $next_run_at = date('Y-m-d H:i:s', $enrolled_ts + max(0, (int) $next_step['delay_seconds']));
                $db->where('id', $enrollment_id)->update('sequence_enrollments', array(
                    'current_step' => $next_position, 'next_run_at' => $next_run_at, 'updated_at' => $now_str,
                ));
            } else {
                $db->where('id', $enrollment_id)->update('sequence_enrollments', array(
                    'current_step' => $next_position, 'status' => 'completed', 'next_run_at' => null, 'updated_at' => $now_str,
                ));
            }

            return array('status' => $status, 'detail' => $detail);
        } catch (Throwable $e) {
            log_message('error', 'sequence_advance(' . $enrollment_id . '): ' . $e->getMessage());
            return array('status' => 'failed', 'detail' => $e->getMessage());
        }
    }
}

if (!function_exists('sequence_dry_run')) {
    /**
     * "Test with sample data" (brief item 5): the computed schedule (T+10min etc.) for a
     * sequence's steps, and — when a real subscriber id from this tenant is given — the
     * channel policy verdict a send_message step would get right now. Enrolls nobody, writes
     * nothing.
     */
    function sequence_dry_run(array $steps, $user_id, $sample_subscriber_id = 0)
    {
        $CI =& get_instance();
        if (!class_exists('Automation_engine')) $CI->load->library('automation_engine');
        $gw = null;
        $ctx = array();
        $sample_subscriber_id = (int) $sample_subscriber_id;
        if ($sample_subscriber_id > 0) {
            require_once APPPATH . 'libraries/Automation_gateway.php';
            $gw = new Automation_gateway();
            $ctx = $gw->context((int) $user_id, array(
                'user_id' => (int) $user_id, 'subject_type' => 'subscriber', 'subject_id' => $sample_subscriber_id,
                'payload' => json_encode(array('subscriber_id' => $sample_subscriber_id), JSON_UNESCAPED_UNICODE),
            ));
        }

        $out = array();
        foreach ($steps as $i => $step) {
            $delay = max(0, (int) (isset($step['delay_seconds']) ? $step['delay_seconds'] : 0));
            $row = array(
                'position' => $i,
                'when' => sequence_human_offset($delay),
                'action_type' => isset($step['action_type']) ? $step['action_type'] : '',
                'action_label' => sequence_action_label(isset($step['action_type']) ? $step['action_type'] : ''),
            );
            if (($row['action_type'] === 'send_message') && $gw !== null) {
                $platform = isset($ctx['promote']['platform']) ? $ctx['promote']['platform'] : '';
                $row['policy'] = $gw->policy_decide($platform, array(
                    'sender' => 'automation',
                    'last_inbound_at' => isset($ctx['promote']['last_inbound_at']) ? $ctx['promote']['last_inbound_at'] : null,
                    'opted_out' => !empty($ctx['promote']['opted_out']),
                ));
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('sequence_human_offset')) {
    /** Seconds -> "T +10 minutes" / "T +6 hours" / "T +3 days", owner language (brief §46). */
    function sequence_human_offset($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds === 0) return 'Immediately';
        if ($seconds % 86400 === 0) { $n = $seconds / 86400; return 'T +' . $n . ' day' . ($n == 1 ? '' : 's'); }
        if ($seconds % 3600 === 0) { $n = $seconds / 3600; return 'T +' . $n . ' hour' . ($n == 1 ? '' : 's'); }
        if ($seconds % 60 === 0) { $n = $seconds / 60; return 'T +' . $n . ' minute' . ($n == 1 ? '' : 's'); }
        return 'T +' . $seconds . ' seconds';
    }
}

if (!function_exists('sequence_action_label')) {
    function sequence_action_label($type)
    {
        $c = sequence_action_catalogue();
        return isset($c[$type]) ? $c[$type] : (string) $type;
    }
}
