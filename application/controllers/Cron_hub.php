<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Cron hub — scheduled sales automation. Hit every 5 minutes:
 *   curl http://127.0.0.1/cron_hub/run
 *
 * Runs under a MariaDB advisory lock (GET_LOCK): jobs are NOT all idempotent (the
 * external-CRM drain and the message senders can double-fire on overlapping runs), and
 * runs routinely exceed 5 min because ignore_user_abort keeps PHP going past the curl
 * timeout. The lock makes a second concurrent tick a no-op.
 *
 * Optionally protected by a shared secret: set config 'cron_secret' and pass ?key=... .
 * When no secret is configured the endpoint stays open (backwards compatible), but the
 * cron file passes the key when one is set.
 *
 * Jobs:
 *  - follow-ups: one nudge per silent conversation, inside Meta's 24h window
 *  - daily digest: summary email/WhatsApp once per day at the configured hour
 */
class Cron_hub extends Home
{
    public function __construct()
    {
        parent::__construct();
        set_time_limit(0);
        $this->load->helper(array('channel_send', 'secret'));
    }

    /** One-time CLI maintenance: encrypt CRM secrets written before at-rest encryption
     *  was wired. Idempotent (skips values already 'enc::'). Run once, then it's dead code.
     *  php index.php cron_hub encrypt_crm_secrets */
    public function encrypt_crm_secrets()
    {
        if (!is_cli()) { show_404(); return; }
        $this->load->helper('secret');
        $done = 0;
        if ($this->db->table_exists('crm_external_config')) {
            foreach ($this->db->from('crm_external_config')->get()->result_array() as $r) {
                $u = array();
                foreach (array('client_secret', 'password') as $f) {
                    if (!empty($r[$f]) && strpos($r[$f], 'enc::') !== 0) $u[$f] = secret_encrypt($r[$f]);
                }
                if ($u) { $this->db->where('id', $r['id'])->update('crm_external_config', $u); $done++; }
            }
        }
        if ($this->db->table_exists('crm_sheet_config')) {
            foreach ($this->db->from('crm_sheet_config')->get()->result_array() as $r) {
                if (!empty($r['service_account_json']) && strpos($r['service_account_json'], 'enc::') !== 0) {
                    $this->db->where('id', $r['id'])->update('crm_sheet_config', array('service_account_json' => secret_encrypt($r['service_account_json'])));
                    $done++;
                }
            }
        }
        echo "encrypted {$done} config row(s)\n";
    }

    /**
     * Retention: the log/message tables grow unbounded (only ai_conversation_history had a
     * TTL, and it pruned only the active subscriber). Trim in bounded batches once an hour
     * so a single run never locks a large delete. Conservative windows — nothing a report
     * needs is younger than these.
     */
    protected function _run_retention()
    {
        // hourly, on the top of the hour-ish, and only one worker (we already hold the cron lock)
        static $windows = array(
            'livechat_messages'    => array('col' => 'conversation_time', 'days' => 120),
            'ai_conversation_history' => array('col' => 'created_at', 'days' => 60),
            'ai_usage_log'         => array('col' => 'created_at', 'days' => 120),
            'ai_price_guard_log'   => array('col' => 'created_at', 'days' => 120),
            'ai_deflect_alert_log' => array('col' => 'alerted_at', 'days' => 180),
        );
        $deleted = array();
        foreach ($windows as $table => $w) {
            if (!$this->db->table_exists($table)) continue;
            $cutoff = date('Y-m-d H:i:s', time() - 86400 * $w['days']);
            $this->db->where($w['col'] . ' <', $cutoff)->limit(5000)->delete($table);
            $n = (int) $this->db->affected_rows();
            if ($n > 0) $deleted[$table] = $n;
        }
        // external-CRM queue: drop settled rows (sent/failed) older than 30 days
        if ($this->db->table_exists('crm_external_log')) {
            $this->db->where_in('status', array('sent', 'failed'))
                ->where('created_at <', date('Y-m-d H:i:s', time() - 86400 * 30))->limit(5000)->delete('crm_external_log');
            $n = (int) $this->db->affected_rows();
            if ($n > 0) $deleted['crm_external_log'] = $n;
        }
        return empty($deleted) ? array('nothing_to_prune' => true) : $deleted;
    }

    /** Optional shared-secret gate. No secret configured -> open (unchanged behaviour). */
    private function _authorized()
    {
        $secret = (string) $this->config->item('cron_secret');
        if ($secret === '') return true;
        return hash_equals($secret, (string) $this->input->get('key', true));
    }

    public function run()
    {
        if (!$this->_authorized()) { $this->output->set_status_header(403); echo 'forbidden'; return; }

        // Advisory lock: bail immediately if another run is still going, so overlapping
        // ticks can't double-send leads/follow-ups/digests.
        $got = $this->db->query("SELECT GET_LOCK('monkeybot_cron_hub', 0) AS l")->row();
        if (empty($got) || (int) $got->l !== 1) {
            echo json_encode(array('skipped' => 'another run in progress')) . "\n";
            return;
        }
        try {
            $out = array();
            $out['followups'] = $this->_run_followups();
            $out['coupon_reminders'] = $this->_run_coupon_reminders();
            $out['digest'] = $this->_run_digest();
            $out['reengage'] = $this->_run_reengage();
            $out['external_crm'] = $this->_run_external_crm();
            $out['deflect_alerts'] = $this->_run_deflect_alerts();
            $out['retention'] = $this->_run_retention();
            echo json_encode($out) . "\n";
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('monkeybot_cron_hub')");
        }
    }

    /**
     * SPEC-27: alert the owner when the bot repeatedly can't answer the SAME thing, so a
     * real gap (a price, a policy, a service) gets filled before it costs more customers.
     * Groups new unanswered questions by a normalized topic key; when a topic crosses the
     * threshold it sends one alert and records it, so the same gap never nags twice.
     */
    protected function _run_deflect_alerts()
    {
        if (!$this->db->table_exists('ai_deflect_alert_log') || !$this->db->table_exists('ai_unanswered_questions')) {
            return array('skipped' => 'not installed');
        }
        try {
            $settings = $this->db->from('sales_automation_settings')->where('deflect_alert_enabled', '1')->get()->result_array();
        } catch (Exception $e) { return array('error' => $e->getMessage()); }

        $sent = 0;
        foreach ($settings as $s) {
            $uid = (int) $s['user_id'];
            $threshold = max(2, (int) $s['deflect_alert_threshold']);
            // frequent unanswered topics not yet alerted
            $rows = $this->db->query(
                "SELECT LOWER(TRIM(question)) topic, MAX(TRIM(question)) sample, COUNT(*) c, MAX(social_media) ch
                 FROM ai_unanswered_questions
                 WHERE user_id=? AND status='new' AND CHAR_LENGTH(TRIM(question)) >= 4
                 GROUP BY LOWER(TRIM(question)) HAVING c >= ?",
                array($uid, $threshold))->result_array();
            if (empty($rows)) continue;

            foreach ($rows as $r) {
                $topic_key = mb_substr((string) $r['topic'], 0, 180);
                $already = $this->db->from('ai_deflect_alert_log')->where('user_id', $uid)->where('topic_key', $topic_key)->count_all_results();
                if ($already > 0) continue;   // one alert per gap, ever

                $text = "⚠️ MonkeyBot: the bot couldn't answer this " . (int) $r['c'] . " times\n\n"
                    . "\"" . mb_substr((string) $r['sample'], 0, 160) . "\"\n\n"
                    . "Channel: " . $r['ch'] . "\n"
                    . "Add the answer: " . site_url('missed_questions');

                $delivered = false;
                if (!empty($s['deflect_alert_whatsapp'])) {
                    list($ok, $err) = channel_send_text($uid, 'wa', $s['deflect_alert_whatsapp'], $text);
                    if ($ok) $delivered = true; else log_message('error', 'deflect alert wa: ' . $err);
                }
                if (!empty($s['deflect_alert_email'])) {
                    try {
                        $this->_email_send_function('', nl2br(htmlspecialchars($text)), $s['deflect_alert_email'], 'MonkeyBot: a question the bot keeps missing', '', '', $uid);
                        $delivered = true;
                    } catch (Exception $e) { log_message('error', 'deflect alert email: ' . $e->getMessage()); }
                }

                // record even if delivery failed, so a broken channel doesn't loop every 5 min
                $this->db->insert('ai_deflect_alert_log', array(
                    'user_id' => $uid, 'topic_key' => $topic_key, 'hits' => (int) $r['c'],
                    'alerted_at' => date('Y-m-d H:i:s'),
                ));
                if ($delivered) $sent++;
            }
        }
        return array('sent' => $sent);
    }

    /**
     * SPEC-23: push AI-captured leads to the external CRM (8xCRM).
     *
     * Runs here rather than inline in Ai_tools::t_save_lead because their storeLead
     * takes 10-14s — inline it would stall the customer's reply by that much on every
     * lead. Queued here it is invisible to the customer and gets retries.
     */
    protected function _run_external_crm()
    {
        if (!$this->db->table_exists('crm_external_log')) return array('skipped' => 'not installed');

        try {
            $this->load->helper('external_crm');
            if (!function_exists('xcrm_drain')) return array('skipped' => 'helper missing');
            return xcrm_drain(20);
        } catch (Exception $e) {
            log_message('error', 'SPEC-23 external crm cron: ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * SPEC-19: re-engagement broadcasts. Paces itself from what it has already
     * sent, so a missed tick never produces a burst, and only ever delivers to
     * contacts inside Meta's 24h window.
     */
    protected function _run_reengage()
    {
        if (!$this->db->table_exists('reengage_campaign')) return array('skipped' => 'not installed');

        try {
            $this->load->library('reengage_sender');
            return $this->reengage_sender->run();
        } catch (Exception $e) {
            log_message('error', 'SPEC-19 reengage cron: ' . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }

    /**
     * SPEC-06 (deferred part): remind customers about unused AI-issued
     * coupons expiring within 48h. The recipient is recovered from the AI
     * conversation where the coupon code was handed out. One reminder per
     * coupon (unique key).
     */
    protected function _run_coupon_reminders()
    {
        if (!$this->db->table_exists('ecommerce_coupon')) return array('sent' => 0);
        $coupons = $this->db->query(
            "SELECT c.user_id, c.coupon_code, c.coupon_amount, c.expiry_date
             FROM ecommerce_coupon c
             WHERE c.used = 0 AND c.status = '1' AND c.coupon_code LIKE 'AI%'
               AND c.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)
               AND NOT EXISTS (SELECT 1 FROM ai_coupon_reminders r WHERE r.coupon_code = c.coupon_code)
             LIMIT 100"
        )->result_array();

        $sent = 0; $failed = 0; $no_recipient = 0;
        foreach ($coupons as $cp) {
            $conv = $this->db->query(
                "SELECT page_id, subscribe_id, social_media, ai_reply FROM ai_conversation_history
                 WHERE user_id = ? AND ai_reply LIKE ? ORDER BY id DESC LIMIT 1",
                array((int) $cp['user_id'], '%' . $cp['coupon_code'] . '%')
            )->row_array();

            $row = array(
                'user_id' => (int) $cp['user_id'], 'coupon_code' => $cp['coupon_code'],
                'created_at' => date('Y-m-d H:i:s'),
            );
            if (empty($conv) || $conv['social_media'] === 'tiktok') {
                $row['status'] = 'no_recipient';
                $this->basic->insert_data('ai_coupon_reminders', $row);
                $no_recipient++;
                continue;
            }

            $is_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', (string) $conv['ai_reply']);
            $expires = date('Y-m-d', strtotime($cp['expiry_date']));
            $message = $is_arabic
                ? 'تذكير سريع 😊 كوبون الخصم ' . $cp['coupon_code'] . ' (' . (int) $cp['coupon_amount'] . '%) لسه متستخدمش وهينتهي يوم ' . $expires . '. تحب تكمل طلبك قبل ما يخلص؟'
                : 'Quick reminder 😊 your ' . (int) $cp['coupon_amount'] . '% coupon ' . $cp['coupon_code'] . ' is still unused and expires on ' . $expires . '. Want to complete your order before it runs out?';

            list($ok, $err) = channel_send_text($cp['user_id'], $conv['social_media'], $conv['subscribe_id'], $message, (string) $conv['page_id']);
            $row['social_media'] = $conv['social_media'];
            $row['subscribe_id'] = (string) $conv['subscribe_id'];
            $row['status'] = $ok ? 'sent' : 'failed';
            $row['error'] = $ok ? null : $err;
            $this->basic->insert_data('ai_coupon_reminders', $row);
            if ($ok) $sent++; else $failed++;
        }
        return array('sent' => $sent, 'failed' => $failed, 'no_recipient' => $no_recipient);
    }

    /**
     * Follow up silent conversations: last exchange older than the configured
     * delay but younger than 23h (Meta standard messaging window), one
     * follow-up per conversation lull (unique key on last_interaction).
     */
    protected function _run_followups()
    {
        $settings = $this->db->from('sales_automation_settings')->where('followup_enabled', '1')->get()->result_array();
        $sent = 0; $failed = 0; $skipped = 0;

        foreach ($settings as $s) {
            $uid = (int) $s['user_id'];
            $delay = max(15, (int) $s['followup_delay_minutes']);

            $convs = $this->db->query(
                "SELECT user_id, page_id, subscribe_id, social_media,
                        MAX(created_at) AS last_at,
                        SUBSTRING_INDEX(GROUP_CONCAT(human_message ORDER BY created_at DESC SEPARATOR 0x01), 0x01, 1) AS last_msg
                 FROM ai_conversation_history
                 WHERE user_id = ?
                 GROUP BY user_id, page_id, subscribe_id, social_media
                 HAVING last_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                    AND last_at >= DATE_SUB(NOW(), INTERVAL 23 HOUR)",
                array($uid, $delay)
            )->result_array();

            foreach ($convs as $c) {
                if ($c['social_media'] === 'tiktok') { $skipped++; continue; } // no DM API

                // one follow-up per lull
                $dup = $this->db->from('ai_followups')->where(array(
                    'user_id' => $uid, 'social_media' => $c['social_media'],
                    'page_id' => (string) $c['page_id'], 'subscribe_id' => (string) $c['subscribe_id'],
                    'last_interaction' => $c['last_at'],
                ))->limit(1)->get()->row_array();
                if (!empty($dup)) { $skipped++; continue; }

                // customer already left contact info in their last message -> lead captured
                if (preg_match('/\d{7,}|@[\w.\-]+\.\w{2,}/u', (string) $c['last_msg'])) { $skipped++; continue; }

                // bot paused for this subscriber (human handoff)
                $sub = $this->basic->get_data('messenger_bot_subscriber',
                    array('where' => array('subscribe_id' => $c['subscribe_id'], 'social_media' => $c['social_media'], 'user_id' => $uid)),
                    array('id', 'bot_paused_until'), '', 1);
                if (!empty($sub[0]['bot_paused_until']) && $sub[0]['bot_paused_until'] > date('Y-m-d H:i:s')) { $skipped++; continue; }

                // fb/ig history also contains COMMENT conversations (SPEC-01);
                // comment/story authors have no DM thread and Graph rejects
                // them — only follow up subscribers with a real inbound DM
                if (in_array($c['social_media'], array('fb', 'ig'))) {
                    if (empty($sub[0]['id'])) { $skipped++; continue; }
                    $dm = $this->db->from('livechat_messages')->where(array(
                        'user_id' => $uid, 'subscriber_id' => $c['subscribe_id'],
                        'platform' => $c['social_media'], 'sender' => 'user',
                    ))->limit(1)->get()->row_array();
                    if (empty($dm)) { $skipped++; continue; }
                }

                // lead already registered in the CRM -> the team owns it now
                if (!empty($sub[0]['id'])) {
                    $deal = $this->db->from('crm_deals')->where('user_id', $uid)
                        ->where('subscriber_id', (int) $sub[0]['id'])->where('status', 'open')->limit(1)->get()->row_array();
                    if (!empty($deal)) { $skipped++; continue; }
                }

                $message = $this->_followup_text($s, (string) $c['last_msg']);
                list($ok, $err) = channel_send_text($uid, $c['social_media'], $c['subscribe_id'], $message, (string) $c['page_id']);

                $this->basic->insert_data('ai_followups', array(
                    'user_id' => $uid, 'social_media' => $c['social_media'],
                    'page_id' => (string) $c['page_id'], 'subscribe_id' => (string) $c['subscribe_id'],
                    'last_interaction' => $c['last_at'], 'message' => $message,
                    'status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : $err,
                    'created_at' => date('Y-m-d H:i:s'),
                ));
                if ($ok) { $sent++; } else { $failed++; log_message('error', 'followup failed ' . $c['social_media'] . '/' . $c['subscribe_id'] . ': ' . $err); }
            }
        }
        return array('sent' => $sent, 'failed' => $failed, 'skipped' => $skipped);
    }

    /**
     * Pick the follow-up text: custom template if set, else a default —
     * Arabic when the customer's last message contains Arabic letters.
     */
    protected function _followup_text($settings, $last_msg)
    {
        $is_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', $last_msg);
        if ($is_arabic) {
            return trim((string) $settings['followup_message_ar']) !== ''
                ? $settings['followup_message_ar']
                : 'لسه معاك 😊 لو حابب نكمل على استفسارك أنا موجود، وتحب أخلي الفريق يتواصل معاك على الواتساب؟';
        }
        return trim((string) $settings['followup_message_en']) !== ''
            ? $settings['followup_message_en']
            : "Still here for you 😊 Want to pick up where we left off? I can also have the team reach out on WhatsApp.";
    }

    /**
     * Daily digest: one summary per day at (or after) the configured hour.
     */
    protected function _run_digest()
    {
        $settings = $this->db->from('sales_automation_settings')->where('digest_enabled', '1')->get()->result_array();
        $sent = array();
        foreach ($settings as $s) {
            $uid = (int) $s['user_id'];
            if ((int) date('G') < (int) $s['digest_hour']) continue;
            if ($s['last_digest_date'] === date('Y-m-d')) continue;

            $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $convos = (int) ($this->db->query(
                "SELECT COUNT(DISTINCT CONCAT(platform,'|',subscriber_id)) c FROM livechat_messages WHERE user_id=? AND sender='user' AND conversation_time >= ?",
                array($uid, $since))->row()->c ?? 0);
            $by_platform = $this->db->query(
                "SELECT platform, COUNT(DISTINCT subscriber_id) c FROM livechat_messages WHERE user_id=? AND sender='user' AND conversation_time >= ? GROUP BY platform",
                array($uid, $since))->result_array();
            $new_leads = $this->db->query(
                "SELECT title, source, contact_phone FROM crm_deals WHERE user_id=? AND created_at >= ? ORDER BY id DESC", array($uid, $since))->result_array();
            $tasks_due = (int) ($this->db->query(
                "SELECT COUNT(*) c FROM crm_activities WHERE user_id=? AND status='pending' AND due_date <= ?", array($uid, date('Y-m-d 23:59:59')))->row()->c ?? 0);
            $missed_q = (int) ($this->db->query(
                "SELECT COUNT(*) c FROM ai_unanswered_questions WHERE user_id=? AND status='new'", array($uid))->row()->c ?? 0);
            $followups_24h = (int) ($this->db->query(
                "SELECT COUNT(*) c FROM ai_followups WHERE user_id=? AND status='sent' AND created_at >= ?", array($uid, $since))->row()->c ?? 0);

            $plat = array();
            foreach ($by_platform as $bp) $plat[] = $bp['platform'] . ': ' . $bp['c'];
            $lead_lines = array();
            foreach (array_slice($new_leads, 0, 8) as $l) $lead_lines[] = '- ' . $l['title'] . ' (' . $l['source'] . ($l['contact_phone'] ? ', ' . $l['contact_phone'] : '') . ')';

            $text = "MonkeyBot daily summary — " . date('Y-m-d') . "\n\n"
                . "Conversations (24h): {$convos}" . ($plat ? ' [' . implode(', ', $plat) . ']' : '') . "\n"
                . "New leads (24h): " . count($new_leads) . ($lead_lines ? "\n" . implode("\n", $lead_lines) : '') . "\n"
                . "Follow-ups sent (24h): {$followups_24h}\n"
                . "Tasks due today: {$tasks_due} — " . site_url('crm/tasks') . "\n"
                . "Unanswered questions to review: {$missed_q} — " . site_url('missed_questions');

            $delivered = false;
            if (!empty($s['digest_whatsapp'])) {
                list($ok, $err) = channel_send_text($uid, 'wa', $s['digest_whatsapp'], $text);
                if ($ok) $delivered = true; else log_message('error', 'digest wa: ' . $err);
            }
            if (!empty($s['digest_email'])) {
                $html = nl2br(htmlspecialchars($text));
                try {
                    $this->_email_send_function('', $html, $s['digest_email'], 'MonkeyBot daily summary — ' . date('Y-m-d'), '', '', $uid);
                    $delivered = true;
                } catch (Exception $e) { log_message('error', 'digest email: ' . $e->getMessage()); }
            }

            if ($delivered) {
                $this->db->where('id', $s['id'])->update('sales_automation_settings',
                    array('last_digest_date' => date('Y-m-d'), 'updated_at' => date('Y-m-d H:i:s')));
                $sent[] = $uid;
            }
        }
        return array('sent_for_users' => $sent);
    }
}
