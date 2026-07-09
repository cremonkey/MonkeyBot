<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-19: the cron-side send engine.
 *
 * Called once per Cron_hub tick. Each tick:
 *   - refuses to run outside the campaign's schedule or inside quiet hours
 *   - computes how many sends it is allowed from what was ACTUALLY sent, so a
 *     cron that missed three hours cannot burst
 *   - claims each recipient with a conditional UPDATE, so overlapping ticks
 *     cannot double-send
 *   - re-derives eligibility immediately before sending, because the list was
 *     built hours ago and windows close
 *   - halts the whole campaign after 5 consecutive hard errors
 *
 * The rate limit exists for Graph quotas, for looking human, and to stop 300
 * customers replying at once. It confers no compliance. Compliance comes from
 * only ever sending to in_window contacts.
 */
class Reengage_sender
{
    /** Wall-clock ceiling per tick, so we never overlap the next cron run. */
    const TICK_SECONDS = 60;

    /** Consecutive hard errors before a campaign is halted. */
    const ERROR_HALT_THRESHOLD = 5;

    private $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->helper('reengage');
        $this->ci->load->helper('channel_send');
        $this->ci->load->library('fb_rx_login');
    }

    /**
     * Process every running campaign. Returns a per-campaign summary for the
     * cron log.
     */
    public function run()
    {
        $started = time();
        $summary = array();

        $campaigns = $this->ci->basic->get_data('reengage_campaign', array(
            'where' => array('status' => 'running'),
        ), '', '', 20, 0, 'id ASC');

        foreach ($campaigns as $campaign) {
            if ((time() - $started) >= self::TICK_SECONDS) break;
            $summary[$campaign['id']] = $this->run_campaign($campaign, $started);
        }

        $this->expire_stale_rows();

        return $summary;
    }

    private function run_campaign($campaign, $tick_started)
    {
        $campaign_id = (int) $campaign['id'];
        $result = array('sent' => 0, 'skipped' => 0, 'failed' => 0, 'reason' => '');

        $tz = $campaign['timezone'] !== '' ? $campaign['timezone'] : 'UTC';
        $now = time();

        if (!empty($campaign['schedule_time']) && strtotime($campaign['schedule_time']) > $now) {
            $result['reason'] = 'not yet scheduled';
            return $result;
        }

        // Quiet hours and the daily cap are stated in the page's local time.
        // time() is timezone-free, so both must be rendered through $tz --
        // otherwise a Cairo page's 22:00-08:00 quiet window would be enforced
        // on UTC and let the cron send at 1am local.
        $now_hm = $this->format_in($tz, 'H:i', $now);
        if (reengage_in_quiet_hours($now_hm, $campaign['quiet_start'], $campaign['quiet_end'])) {
            $result['reason'] = 'quiet hours';
            return $result;
        }

        $allowed = reengage_rate_budget(
            $campaign['messages_per_hour'],
            $this->sent_since($campaign_id, date('Y-m-d H:i:s', $now - 3600)),
            $campaign['daily_cap'],
            $this->sent_since($campaign_id, date('Y-m-d H:i:s', $this->day_start_epoch($tz, $now)))
        );

        if ($allowed <= 0) {
            $result['reason'] = 'rate budget exhausted';
            return $result;
        }

        $page = $this->page_for($campaign);
        if ($page === null && $campaign['social_media'] !== 'web') {
            $this->halt($campaign_id, 'page or access token missing');
            $result['reason'] = 'halted: no page token';
            return $result;
        }

        $consecutive_errors = (int) $campaign['consecutive_errors'];

        // 'reentered' first: those customers are actively talking to us, their
        // window is open, and it will close again soon.
        $candidates = $this->claimable($campaign_id, $allowed);

        foreach ($candidates as $row) {
            if ((time() - $tick_started) >= self::TICK_SECONDS) break;
            if ($result['sent'] >= $allowed) break;

            if (!$this->claim($row['id'])) continue; // another tick owns it

            $decision = $this->evaluate($row, $campaign, $now);

            if ($decision['action'] === 'defer') {
                $this->release($row['id'], $decision['state'], $decision['reason']);
                $result['skipped']++;
                continue;
            }
            if ($decision['action'] === 'skip') {
                $this->finish($row['id'], 'skipped', array('skip_reason' => $decision['reason']));
                $result['skipped']++;
                continue;
            }

            $outcome = $this->deliver($campaign, $page, $row);

            if ($outcome['ok']) {
                $this->finish($row['id'], 'sent', array(
                    'sent_at' => date('Y-m-d H:i:s'),
                    'message_sent_id' => $outcome['message_id'],
                ));
                $result['sent']++;
                $consecutive_errors = 0;
            } else {
                $this->apply_error($campaign_id, $row, $outcome);
                $result['failed']++;

                if ($outcome['fatal']) {
                    $this->halt($campaign_id, 'Graph error ' . $outcome['code'] . ': ' . $outcome['error']);
                    $result['reason'] = 'halted: fatal Graph error';
                    return $result;
                }
                if ($outcome['backoff']) {
                    $result['reason'] = 'rate limited by Graph, backing off';
                    break;
                }
                if ($outcome['hard']) {
                    $consecutive_errors++;
                    if ($consecutive_errors >= self::ERROR_HALT_THRESHOLD) {
                        $this->halt($campaign_id, self::ERROR_HALT_THRESHOLD . ' consecutive errors; last: ' . $outcome['error']);
                        $result['reason'] = 'halted: consecutive errors';
                        return $result;
                    }
                } else {
                    $consecutive_errors = 0;
                }
            }

            $this->ci->basic->update_data('reengage_campaign', array('id' => $campaign_id), array(
                'consecutive_errors' => $consecutive_errors,
            ));

            sleep(reengage_jitter_seconds($campaign['jitter_min_sec'], $campaign['jitter_max_sec']));
        }

        $this->complete_if_drained($campaign_id);

        return $result;
    }

    /**
     * Decide what happens to a claimed row, right now.
     *
     * Eligibility is recomputed from the live subscriber row rather than trusted
     * from build time. Exclusions are re-checked for the same reason.
     *
     * @return array ['action'=>'send'|'defer'|'skip', 'state'=>?, 'reason'=>?]
     */
    private function evaluate($row, $campaign, $now)
    {
        $subs = $this->ci->basic->get_data('messenger_bot_subscriber', array('where' => array('id' => $row['subscriber_auto_id'])),
            array('id', 'status', 'unavailable', 'bot_paused_until', 'last_subscriber_interaction_time', 'first_name', 'last_name', 'subscribe_id'));

        if (empty($subs)) return array('action' => 'skip', 'reason' => 'subscriber gone');
        $sub = $subs[0];

        if ($sub['status'] !== '1') return array('action' => 'skip', 'reason' => 'unsubscribed');
        if ($sub['unavailable'] === '1') return array('action' => 'skip', 'reason' => 'unavailable');

        $paused = reengage_to_timestamp($sub['bot_paused_until']);
        if ($paused !== null && $paused > $now) {
            return array('action' => 'defer', 'state' => 'waiting_reentry', 'reason' => 'human_handoff');
        }

        if ($this->opted_out($campaign['user_id'], $sub['subscribe_id'])) {
            return array('action' => 'skip', 'reason' => 'opted_out');
        }

        $last = reengage_to_timestamp($sub['last_subscriber_interaction_time']);
        $bucket = reengage_classify($sub['last_subscriber_interaction_time'], $now);

        if (!reengage_is_sendable($bucket)) {
            // Window closed since the list was built, or never was open.
            return array('action' => 'defer', 'state' => 'waiting_reentry', 'reason' => $bucket);
        }

        // A customer who just came back is mid-conversation with the AI bot.
        // Dropping a canned promo on top of that is worse than waiting.
        if ($row['state'] === 'reentered' && $last !== null) {
            $idle_seconds = $now - $last;
            $required = ((int) $campaign['reentry_idle_minutes']) * 60;
            if ($idle_seconds < $required) {
                return array('action' => 'defer', 'state' => 'reentered', 'reason' => 'conversation still active');
            }
        }

        return array('action' => 'send', 'subscriber' => $sub);
    }

    /**
     * Send one message. `web` bypasses Meta entirely and exists so the pacing
     * logic can be exercised end-to-end without risking the page.
     *
     * @return array ['ok'=>bool,'message_id'=>string,'code'=>string,'error'=>string,
     *                'hard'=>bool,'fatal'=>bool,'backoff'=>bool,'unavailable'=>bool]
     */
    private function deliver($campaign, $page, $row)
    {
        $subs = $this->ci->basic->get_data('messenger_bot_subscriber',
            array('where' => array('id' => $row['subscriber_auto_id'])),
            array('first_name', 'last_name', 'subscribe_id'));
        $sub = !empty($subs) ? $subs[0] : array('first_name' => '', 'last_name' => '', 'subscribe_id' => $row['subscribe_id']);

        $message = $this->message_for($campaign, $row, $sub);

        if ($campaign['social_media'] === 'web') {
            $text = isset($message['text']) ? $message['text'] : '[non-text payload]';
            list($ok, $err) = channel_send_text($campaign['user_id'], 'web', $sub['subscribe_id'], $text, 'webchat');
            return $this->outcome($ok, $ok ? 'web-' . $row['id'] : '', '', $ok ? '' : $err);
        }

        $payload = json_encode(array(
            'recipient' => array('id' => $sub['subscribe_id']),
            'messaging_type' => 'RESPONSE', // valid only inside the 24h window
            'message' => $message,
        ));

        $response = $this->ci->fb_rx_login->send_non_promotional_message_subscription($payload, $page['page_access_token']);

        if (isset($response['message_id'])) {
            return $this->outcome(true, $response['message_id'], '', '');
        }

        $code = isset($response['error']['code']) ? (string) $response['error']['code'] : '';
        $sub_code = isset($response['error']['error_subcode']) ? (string) $response['error']['error_subcode'] : '';
        $error = isset($response['error']['message']) ? $response['error']['message'] : 'unknown Graph failure';
        if ($sub_code !== '') $code .= '/' . $sub_code;

        return $this->outcome(false, '', $code, $error);
    }

    /**
     * Classify a Graph failure.
     *
     *   551            user unavailable        -> mark subscriber, skip row
     *   10 / 2018278   outside allowed window  -> requeue; our classifier lost a race
     *   613, 4         rate limited            -> back off this tick, no state change
     *   100            bad param / dead tag    -> halt; this is a bug in our payload
     *   200            missing permission      -> halt; the app lost access
     */
    private function outcome($ok, $message_id, $code, $error)
    {
        $out = array(
            'ok' => (bool) $ok, 'message_id' => (string) $message_id,
            'code' => (string) $code, 'error' => (string) $error,
            'hard' => false, 'fatal' => false, 'backoff' => false,
            'unavailable' => false, 'requeue' => false,
        );
        if ($ok) return $out;

        $base = explode('/', $out['code']);
        $base = $base[0];

        if ($base === '551') {
            $out['unavailable'] = true;
        } elseif ($base === '10' || strpos($out['code'], '2018278') !== false) {
            $out['requeue'] = true;
        } elseif ($base === '613' || $base === '4') {
            $out['backoff'] = true;
        } elseif ($base === '100' || $base === '200') {
            $out['fatal'] = true;
        } else {
            $out['hard'] = true;
        }

        return $out;
    }

    private function apply_error($campaign_id, $row, $outcome)
    {
        $patch = array(
            'error_code' => substr($outcome['code'], 0, 20),
            'error_message' => substr($outcome['error'], 0, 250),
        );

        if ($outcome['unavailable']) {
            $this->ci->basic->update_data('messenger_bot_subscriber',
                array('id' => $row['subscriber_auto_id']),
                array('unavailable' => '1', 'last_error_message' => $outcome['error']));
            $this->finish($row['id'], 'skipped', array_merge($patch, array('skip_reason' => 'unavailable')));
            return;
        }

        if ($outcome['requeue']) {
            $this->finish($row['id'], 'waiting_reentry', $patch);
            return;
        }

        if ($outcome['backoff']) {
            // Graph refused us, not the customer. Restore the row to exactly the
            // state we claimed it from so a rate-limited re-entrant keeps its
            // priority instead of demoting to the back of the pending queue.
            $this->finish($row['id'], $row['state'], $patch);
            return;
        }

        $this->finish($row['id'], 'failed', $patch);
    }

    /** Interpolate name placeholders and append the opt-out line. */
    private function message_for($campaign, $row, $sub)
    {
        $json = ($row['ab_variant'] === 'B' && !empty($campaign['variant_b_json']))
            ? $campaign['variant_b_json']
            : $campaign['message_json'];

        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) $decoded = array('text' => (string) $json);

        if (isset($decoded['text'])) {
            $text = str_replace(
                array('{{first_name}}', '{{last_name}}'),
                array($sub['first_name'], $sub['last_name']),
                $decoded['text']
            );
            $decoded['text'] = $text . "\n\n" . $this->optout_line();
        }

        return $decoded;
    }

    private function optout_line()
    {
        return 'لو مش عايز رسايل تانية ابعت: إيقاف';
    }

    /** Rows this tick may attempt, newest re-entrants first. */
    private function claimable($campaign_id, $limit)
    {
        $this->ci->db->select('id, subscriber_auto_id, subscribe_id, state, ab_variant');
        $this->ci->db->where('campaign_id', (int) $campaign_id);
        $this->ci->db->where_in('state', array('reentered', 'pending'));
        $this->ci->db->order_by("FIELD(state,'reentered','pending')", '', false);
        $this->ci->db->order_by('id', 'ASC');
        $this->ci->db->limit((int) $limit + 10); // headroom for rows that defer
        $query = $this->ci->db->get('reengage_recipient');

        return $query ? $query->result_array() : array();
    }

    /**
     * Take ownership of a row. If another tick already moved it, affected_rows
     * is 0 and we leave it alone. This is what prevents double-sending.
     */
    private function claim($recipient_id)
    {
        $this->ci->db->where('id', (int) $recipient_id);
        $this->ci->db->where_in('state', array('pending', 'reentered'));
        $this->ci->db->update('reengage_recipient', array('state' => 'sending'));

        return $this->ci->db->affected_rows() === 1;
    }

    private function release($recipient_id, $state, $reason)
    {
        $this->ci->basic->update_data('reengage_recipient', array('id' => $recipient_id), array(
            'state' => $state,
            'skip_reason' => substr((string) $reason, 0, 64),
        ));
    }

    private function finish($recipient_id, $state, $patch = array())
    {
        $patch['state'] = $state;
        $this->ci->basic->update_data('reengage_recipient', array('id' => $recipient_id), $patch);
    }

    private function sent_since($campaign_id, $since_sql)
    {
        $this->ci->db->where('campaign_id', (int) $campaign_id);
        $this->ci->db->where('state', 'sent');
        $this->ci->db->where('sent_at >=', $since_sql);
        return (int) $this->ci->db->count_all_results('reengage_recipient');
    }

    private function opted_out($user_id, $subscribe_id)
    {
        return $this->ci->basic->is_exist('reengage_optout', array(
            'user_id' => (int) $user_id,
            'subscribe_id' => $subscribe_id,
        ));
    }

    private function page_for($campaign)
    {
        if ((int) $campaign['page_table_id'] === 0) return null;

        $rows = $this->ci->basic->get_data('facebook_rx_fb_page_info', array(
            'where' => array('id' => (int) $campaign['page_table_id'], 'user_id' => (int) $campaign['user_id']),
        ));
        if (empty($rows) || $rows[0]['page_access_token'] === '') return null;

        return $rows[0];
    }

    private function halt($campaign_id, $reason)
    {
        $this->ci->basic->update_data('reengage_campaign', array('id' => $campaign_id), array(
            'status' => 'halted',
            'halt_reason' => $reason,
            'completed_at' => date('Y-m-d H:i:s'),
        ));
        log_message('error', 'SPEC-19 campaign ' . $campaign_id . ' halted: ' . $reason);
    }

    /** A campaign with nothing left to attempt is done. */
    private function complete_if_drained($campaign_id)
    {
        $this->ci->db->where('campaign_id', (int) $campaign_id);
        $this->ci->db->where_in('state', array('pending', 'sending', 'reentered', 'waiting_reentry'));
        if ($this->ci->db->count_all_results('reengage_recipient') > 0) return;

        $this->ci->basic->update_data('reengage_campaign', array('id' => $campaign_id), array(
            'status' => 'done',
            'completed_at' => date('Y-m-d H:i:s'),
        ));
    }

    /** Queued messages go stale; a six-month-old promo helps nobody. */
    private function expire_stale_rows()
    {
        $this->ci->db->where_in('state', array('waiting_reentry', 'pending', 'reentered'));
        $this->ci->db->where('expires_at IS NOT NULL', null, false);
        $this->ci->db->where('expires_at <', date('Y-m-d H:i:s'));
        $this->ci->db->update('reengage_recipient', array('state' => 'expired'));
    }

    /** Render an epoch in the campaign's timezone. */
    private function format_in($tz, $format, $epoch)
    {
        try {
            $dt = new DateTime('@' . $epoch);
            $dt->setTimezone(new DateTimeZone($tz));
            return $dt->format($format);
        } catch (Exception $e) {
            return date($format, $epoch); // unknown tz: fall back to server time
        }
    }

    /** Epoch of midnight today, in the campaign's timezone. */
    private function day_start_epoch($tz, $epoch)
    {
        try {
            $dt = new DateTime('@' . $epoch);
            $dt->setTimezone(new DateTimeZone($tz));
            $dt->setTime(0, 0, 0);
            return $dt->getTimestamp();
        } catch (Exception $e) {
            return strtotime(date('Y-m-d 00:00:00', $epoch));
        }
    }
}
