<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Cron hub — scheduled sales automation. Hit every 5 minutes:
 *   curl http://127.0.0.1/cron_hub/run
 * Public endpoint (same pattern as webhooks / tiktok cron); every job is
 * idempotent so overlapping or repeated hits are safe.
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

    public function run()
    {
        $out = array();
        $out['followups'] = $this->_run_followups();
        $out['coupon_reminders'] = $this->_run_coupon_reminders();
        $out['digest'] = $this->_run_digest();
        $out['reengage'] = $this->_run_reengage();
        echo json_encode($out) . "\n";
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
