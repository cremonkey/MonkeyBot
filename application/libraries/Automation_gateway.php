<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Everything the Automation Engine does to the outside world: database reads and writes,
 * channel policy, outbound messages, webhooks, Google Sheets, sleeping between retries.
 *
 * Kept apart from Automation_engine so the engine's decisions (match / retry / policy /
 * idempotency / tenant isolation) can be tested without CodeIgniter, a database or a
 * network. tests/automation/run.php substitutes a fake with exactly these methods.
 *
 * CONTRACT
 *  - every write is scoped to $user_id. A missing user_id scope here is a tenant leak.
 *  - every action method returns array(bool ok, string|null error, bool retryable).
 *    'retryable' means a later attempt could plausibly succeed (timeout, 5xx, transport).
 *    A wrong configuration or a policy verdict is NOT retryable.
 *  - no method throws; the engine still catches, but failures belong in the return value.
 */
class Automation_gateway
{
    /** @var CI_Controller */
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->helper(array('event', 'crm', 'channel_policy', 'channel_send', 'reengage'));
    }

    // ---------------------------------------------------------------- reads

    /** Active rules of ONE tenant listening to ONE event type. */
    public function active_rules($user_id, $event_type)
    {
        try {
            return $this->CI->db->from('automation_rules')
                ->where('user_id', (int) $user_id)
                ->where('trigger_event', $event_type)
                ->where('status', 'active')
                ->order_by('id', 'ASC')->get()->result_array();
        } catch (Throwable $e) {
            $this->log('error', 'automation active_rules: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Small, tenant-scoped context loader: the subscriber and/or deal the event is about,
     * plus the handful of values promoted to the top of the condition namespace.
     * Deliberately NOT a Customer-360 loader — a worker tick must stay cheap.
     */
    public function context($user_id, array $event)
    {
        $user_id = (int) $user_id;
        $out = array('promote' => array());
        try {
            $payload = event_payload($event);
            $subject_type = isset($event['subject_type']) ? $event['subject_type'] : '';
            $subject_id = isset($event['subject_id']) ? $event['subject_id'] : '';

            $sid = 0;
            if (!empty($payload['subscriber_id'])) $sid = (int) $payload['subscriber_id'];
            elseif ($subject_type === 'subscriber') $sid = (int) $subject_id;

            $deal_id = 0;
            if (!empty($payload['deal_id'])) $deal_id = (int) $payload['deal_id'];
            elseif ($subject_type === 'deal') $deal_id = (int) $subject_id;

            if ($deal_id > 0 && $this->CI->db->table_exists('crm_deals')) {
                $d = $this->CI->db->from('crm_deals')->where('id', $deal_id)->where('user_id', $user_id)->limit(1)->get()->row_array();
                if (!empty($d)) {
                    $out['deal'] = $d;
                    if ($sid <= 0 && !empty($d['subscriber_id'])) $sid = (int) $d['subscriber_id'];
                }
            }

            if ($sid > 0) {
                $s = $this->CI->db->select('id, user_id, subscribe_id, page_id, page_table_id, full_name, email, phone_number, lead_score, social_media, last_subscriber_interaction_time, bot_paused_until')
                    ->from('messenger_bot_subscriber')->where('id', $sid)->where('user_id', $user_id)->limit(1)->get()->row_array();
                if (!empty($s)) {
                    $s['platform'] = $s['social_media'];
                    $out['subscriber'] = $s;
                    $out['promote'] = array(
                        'subscriber_id'   => (int) $s['id'],
                        'subscribe_id'    => $s['subscribe_id'],
                        'page_id'         => $s['page_id'],
                        'full_name'       => $s['full_name'],
                        'email'           => $s['email'],
                        'phone'           => $s['phone_number'],
                        'lead_score'      => (int) $s['lead_score'],
                        'platform'        => $s['social_media'],
                        'last_inbound_at' => $s['last_subscriber_interaction_time'],
                        'opted_out'       => $this->is_opted_out($user_id, $s['subscribe_id'], $s['social_media']),
                    );
                }
            }
        } catch (Throwable $e) {
            $this->log('error', 'automation context: ' . $e->getMessage());
        }
        return $out;
    }

    protected function is_opted_out($user_id, $subscribe_id, $social_media)
    {
        try {
            if (!$this->CI->db->table_exists('reengage_optout')) return false;
            return $this->CI->db->from('reengage_optout')->where('user_id', (int) $user_id)
                ->where('subscribe_id', $subscribe_id)->count_all_results() > 0;
        } catch (Throwable $e) {
            return false;   // safe here: the policy layer denies automation outside the window anyway
        }
    }

    // ------------------------------------------------------------- run log

    /**
     * Claim (rule, event). Returns the new run id, or 0 when a run already exists — the
     * UNIQUE(rule_id, event_id) index is what makes replay safe, not a SELECT first.
     */
    public function run_claim($rule_id, $event_id)
    {
        try {
            $this->CI->db->query(
                'INSERT IGNORE INTO automation_runs (rule_id, event_id, status, detail, created_at) VALUES (?, ?, ?, ?, ?)',
                array((int) $rule_id, (int) $event_id, 'skipped', 'running', date('Y-m-d H:i:s'))
            );
            return (int) $this->CI->db->insert_id();   // 0 when the row already existed
        } catch (Throwable $e) {
            $this->log('error', 'automation run_claim: ' . $e->getMessage());
            return 0;
        }
    }

    public function run_finish($run_id, $status, $detail)
    {
        try {
            $this->CI->db->where('id', (int) $run_id)->update('automation_runs', array(
                'status' => $status, 'detail' => mb_substr((string) $detail, 0, 1000),
            ));
        } catch (Throwable $e) {
            $this->log('error', 'automation run_finish: ' . $e->getMessage());
        }
    }

    public function action_log($run_id, $index, $type, $status, $error, $retry_count)
    {
        try {
            $row = array(
                'run_id' => (int) $run_id, 'action_index' => (int) $index,
                'action_type' => mb_substr((string) $type, 0, 48), 'status' => mb_substr((string) $status, 0, 32),
                'error' => $error === null ? null : mb_substr((string) $error, 0, 1000),
                'retry_count' => (int) $retry_count, 'created_at' => date('Y-m-d H:i:s'),
            );
            // Task 13: correlation id (2026-10-03d-request-ids.sql), cached in a static like
            // Inbox.php's own field_exists caching, and omitted until the column exists so this
            // keeps working, byte-identical to before, pre-migration.
            static $has_request_id = null;
            if ($has_request_id === null) $has_request_id = $this->CI->db->field_exists('request_id', 'automation_action_log');
            if ($has_request_id && function_exists('request_id_get')) {
                $row['request_id'] = request_id_get();
            }
            $this->CI->db->insert('automation_action_log', $row);
        } catch (Throwable $e) {
            $this->log('error', 'automation action_log: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------- actions

    /**
     * Labels are rows in messenger_bot_broadcast_contact_group linked through
     * messenger_bot_subscribers_label — the same objects Broadcasting filters on, so an
     * automation tag is usable in a campaign immediately.
     */
    public function add_label($user_id, $subscriber_id, $label)
    {
        try {
            $sub = $this->CI->db->select('id, page_table_id, social_media')->from('messenger_bot_subscriber')
                ->where('id', (int) $subscriber_id)->where('user_id', (int) $user_id)->limit(1)->get()->row_array();
            if (empty($sub)) return array(false, 'customer not found for this account', false);

            $g = $this->CI->db->from('messenger_bot_broadcast_contact_group')
                ->where('user_id', (int) $user_id)->where('group_name', $label)->where('deleted', '0')
                ->limit(1)->get()->row_array();
            if (empty($g)) {
                $this->CI->db->insert('messenger_bot_broadcast_contact_group', array(
                    'page_id' => (int) $sub['page_table_id'], 'group_name' => $label, 'user_id' => (int) $user_id,
                    'label_id' => '', 'social_media' => in_array($sub['social_media'], array('fb', 'ig', 'wa', 'web', 'tg'), true) ? $sub['social_media'] : 'fb',
                    'deleted' => '0', 'unsubscribe' => '0', 'invisible' => '0',
                ));
                $gid = (int) $this->CI->db->insert_id();
            } else {
                $gid = (int) $g['id'];
            }
            if ($gid <= 0) return array(false, 'could not create the tag', true);

            $this->CI->db->query('INSERT IGNORE INTO messenger_bot_subscribers_label (contact_group_id, subscriber_table_id) VALUES (?, ?)',
                array($gid, (int) $sub['id']));
            return array(true, null, false);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    public function update_lead_score($user_id, $subscriber_id, $delta)
    {
        try {
            $this->CI->db->set('lead_score', 'GREATEST(0, lead_score + (' . (int) $delta . '))', false)
                ->where('id', (int) $subscriber_id)->where('user_id', (int) $user_id)
                ->update('messenger_bot_subscriber');
            if ($this->CI->db->affected_rows() < 1) return array(false, 'customer not found for this account', false);
            return array(true, null, false);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    /** Reuses crm_helper so a deal an automation makes looks exactly like any other deal. */
    public function create_crm_deal($user_id, $subscriber_id, array $data)
    {
        try {
            if (!function_exists('crm_auto_deal')) $this->CI->load->helper('crm');
            $before = $this->CI->db->from('crm_deals')->where('user_id', (int) $user_id)->count_all_results();
            crm_auto_deal((int) $user_id, (int) $subscriber_id, 'new_lead', array(
                'title' => $data['title'], 'value' => $data['value'], 'currency' => $data['currency'],
                'contact_name' => $data['contact_name'], 'source' => $data['source'],
            ));
            $after = $this->CI->db->from('crm_deals')->where('user_id', (int) $user_id)->count_all_results();
            // crm_auto_deal is idempotent: an existing open deal means "already done", not a failure.
            return array(true, $after > $before ? null : 'an open deal already existed', false);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    public function move_crm_stage($user_id, $deal_id, $subscriber_id, $stage_name)
    {
        try {
            if (!function_exists('crm_ensure_pipeline')) $this->CI->load->helper('crm');
            $q = $this->CI->db->from('crm_deals')->where('user_id', (int) $user_id);
            if ((int) $deal_id > 0) $q->where('id', (int) $deal_id);
            elseif ((int) $subscriber_id > 0) $q->where('subscriber_id', (int) $subscriber_id)->where_in('status', array('open', 'won'));
            else return array(false, 'no deal on this event', false);
            $deal = $q->order_by('id', 'DESC')->limit(1)->get()->row_array();
            if (empty($deal)) return array(false, 'no deal found for this customer', false);

            $stage_id = crm_stage_id((int) $deal['pipeline_id'], $stage_name);
            if (!$stage_id) return array(false, 'stage "' . $stage_name . '" does not exist in this pipeline', false);

            $from = (int) $deal['stage_id'];
            if ($from === (int) $stage_id) {
                // Already there. Returning early is also the loop guard: a rule triggered by
                // lead.stage_changed whose action moves the stage would otherwise emit a new
                // lead.stage_changed every tick, for ever.
                return array(true, 'the deal is already in that stage', false);
            }

            $this->CI->db->where('id', (int) $deal['id'])->where('user_id', (int) $user_id)
                ->update('crm_deals', array('stage_id' => (int) $stage_id, 'updated_at' => date('Y-m-d H:i:s')));
            $this->CI->db->insert('crm_deal_timeline', array(
                'deal_id' => (int) $deal['id'], 'user_id' => (int) $user_id,
                'action' => 'moved to ' . $stage_name . ' (automation)', 'created_at' => date('Y-m-d H:i:s'),
            ));
            event_emit((int) $user_id, 'lead.stage_changed', 'deal', (int) $deal['id'], array(
                'deal_id' => (int) $deal['id'], 'from_stage' => $from, 'to_stage' => (int) $stage_id,
                'to_stage_name' => $stage_name, 'subscriber_id' => (int) $deal['subscriber_id'],
            ));
            return array(true, null, false);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    /**
     * Team notification. WhatsApp goes out through channel_send_text (the same facade the
     * digest and deflection alerts use); email goes through Home::_email_send_function,
     * which is the only email path configured per tenant in this app.
     */
    public function notify_team($user_id, array $p)
    {
        try {
            if ($p['channel'] === 'email') {
                if (!method_exists($this->CI, '_email_send_function')) {
                    return array(false, 'email is only available from a web/cron context', false);
                }
                $this->CI->_email_send_function('', nl2br(htmlspecialchars($p['text'])), $p['to'], $p['subject'], '', '', (int) $user_id);
                return array(true, null, false);
            }
            list($ok, $err) = channel_send_text((int) $user_id, 'wa', $p['to'], $p['text']);
            return array((bool) $ok, $ok ? null : $err, true);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    /** brief 26: one policy layer. The engine calls this BEFORE every customer-facing send. */
    public function policy_decide($platform, array $ctx)
    {
        if (!function_exists('channel_policy_decide')) $this->CI->load->helper('channel_policy');
        return channel_policy_decide($platform, $ctx);
    }

    public function send_text($user_id, $platform, $subscribe_id, $text, $page_id = '')
    {
        try {
            // the policy layer names the WhatsApp Cloud channel separately; the sender does not
            $send_platform = $platform === 'wa_cloud' ? 'wa' : $platform;
            list($ok, $err) = channel_send_text((int) $user_id, $send_platform, $subscribe_id, $text, (string) $page_id);
            return array((bool) $ok, $ok ? null : $err, true);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    /** https only, 5s timeout, certificate verification on, no redirects followed. */
    public function call_webhook($url, array $payload)
    {
        try {
            if (stripos($url, 'https://') !== 0) return array(false, 'webhook URL must start with https://', false);
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'User-Agent: MonkeyBot-Automation/1.0'),
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            ));
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) return array(false, 'connection failed: ' . $err, true);
            if ($code >= 200 && $code < 300) return array(true, null, false);
            // 4xx is the receiver rejecting our payload — retrying sends the same payload.
            return array(false, 'HTTP ' . $code . ' ' . mb_substr((string) $raw, 0, 200), $code >= 500 || $code === 429);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    /**
     * Appends one lead row to the tenant's configured Google Sheet, reusing the CRM sheet
     * sync's service-account plumbing (crm_sheet_helper).
     */
    public function write_google_sheet($user_id, array $row)
    {
        try {
            $this->CI->load->helper('crm_sheet');
            if (!function_exists('crm_sheet_get_config') || !function_exists('crm_sheet_append_rows')) {
                return array(false, 'Google Sheet sync is not available in this build', false);
            }
            $cfg = crm_sheet_get_config((int) $user_id);
            if (empty($cfg)) return array(false, 'no Google Sheet is connected for this account', false);
            // column order must stay crm_sheet_headers(): Date, Channel, Name, Phone, Email, Comment, Deal #, Status
            $res = crm_sheet_append_rows($cfg, array(array(
                date('Y-m-d H:i:s'), $row['event'], $row['name'], $row['phone'], $row['email'], $row['note'], '', 'automation',
            )));
            return $res === true ? array(true, null, false) : array(false, (string) $res, true);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    /**
     * A follow-up task for the team, in crm_activities (SPEC-12) — the same table the CRM's
     * own task list reads. Used by the automation/sequence action 'create_task' (brief §25's
     * "T +1 day: create human follow-up task").
     */
    public function create_task($user_id, $subscriber_id, $deal_id, array $data)
    {
        try {
            if (!$this->CI->db->table_exists('crm_activities')) return array(false, 'tasks are not available in this build', false);
            $subject = trim((string) (isset($data['subject']) ? $data['subject'] : ''));
            if ($subject === '') $subject = 'Follow up';
            $due_minutes = max(0, (int) (isset($data['due_in_minutes']) ? $data['due_in_minutes'] : 0));
            $this->CI->db->insert('crm_activities', array(
                'deal_id'       => (int) $deal_id > 0 ? (int) $deal_id : null,
                'subscriber_id' => (int) $subscriber_id > 0 ? (int) $subscriber_id : null,
                'user_id'       => (int) $user_id,
                'type'          => 'follow_up',
                'subject'       => mb_substr($subject, 0, 150),
                'description'   => mb_substr((string) (isset($data['description']) ? $data['description'] : ''), 0, 2000),
                'due_date'      => date('Y-m-d H:i:s', strtotime('+' . $due_minutes . ' minutes')),
                'status'        => 'pending',
                'created_at'    => date('Y-m-d H:i:s'),
            ));
            return array(true, null, false);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    /**
     * Enroll one customer into a Sequence (brief §25 + §23's "start sequence" action) — how
     * an Automation Rule composes with a Sequence. Idempotent: sequence_enroll() never
     * double-enrolls (UNIQUE(sequence_id, subscriber_id, platform)).
     */
    public function start_sequence($user_id, $sequence_id, $subscriber_id)
    {
        try {
            if (!function_exists('sequence_enroll')) $this->CI->load->helper('sequence');
            $sub = $this->CI->db->select('id, subscribe_id, page_table_id, social_media')
                ->from('messenger_bot_subscriber')->where('id', (int) $subscriber_id)->where('user_id', (int) $user_id)
                ->limit(1)->get()->row_array();
            if (empty($sub)) return array(false, 'customer not found for this account', false);

            list($ok, $err) = sequence_enroll(
                (int) $user_id, (int) $sequence_id, (int) $sub['id'],
                (string) $sub['social_media'], (string) $sub['subscribe_id'], (int) $sub['page_table_id']
            );
            return array($ok, $err, false);
        } catch (Throwable $e) {
            return array(false, $e->getMessage(), true);
        }
    }

    public function sleep_backoff($seconds)
    {
        $s = (int) $seconds;
        if ($s > 0) sleep($s);
    }

    public function log($level, $message)
    {
        log_message($level, $message);
    }
}
