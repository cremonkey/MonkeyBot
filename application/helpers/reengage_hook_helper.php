<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-19 webhook hooks. Both are called from the inbound message path, so both
 * must be cheap and must never throw: a fatal here would break bot replies for
 * every customer, not just re-engagement.
 *
 * Neither hook sends anything. The webhook has to ACK Meta quickly (this
 * codebase already produced duplicate replies once by doing slow work before
 * the ACK). The cron does the sending.
 */

if (!function_exists('reengage_mark_reentry')) {
    /**
     * A queued contact just messaged us, so their 24h window is open again.
     * Flip their waiting rows to 'reentered'; the cron will send once the
     * conversation has been idle for the campaign's reentry_idle_minutes.
     *
     * Called from Messenger_bot::update_subscriber_last_interaction, the single
     * choke point every inbound message passes through.
     */
    function reengage_mark_reentry($subscribe_id)
    {
        if ($subscribe_id === '' || $subscribe_id === null) return;

        $ci = &get_instance();

        try {
            if (!$ci->db->table_exists('reengage_recipient')) return;

            $ci->db->where('subscribe_id', $subscribe_id);
            $ci->db->where('state', 'waiting_reentry');
            $ci->db->update('reengage_recipient', array(
                'state' => 'reentered',
                'reentered_at' => date('Y-m-d H:i:s'),
            ));
        } catch (Exception $e) {
            log_message('error', 'SPEC-19 reengage_mark_reentry: ' . $e->getMessage());
        }
    }
}

if (!function_exists('reengage_check_optout')) {
    /**
     * Honour a stop request immediately.
     *
     * Matched against the whole trimmed message, not a substring: a customer
     * writing "مش عايز اوقف الاوردر" must not be silently unsubscribed. The
     * cost of a missed opt-out is a complaint; complaints restrict pages.
     *
     * @return bool true when the customer opted out
     */
    function reengage_check_optout($user_id, $subscribe_id, $social_media, $text)
    {
        $text = trim((string) $text);
        if ($text === '' || $subscribe_id === '') return false;

        if (!reengage_is_stop_keyword($text)) return false;

        $ci = &get_instance();

        try {
            if (!$ci->db->table_exists('reengage_optout')) return false;

            $ci->db->replace('reengage_optout', array(
                'user_id' => (int) $user_id,
                'subscribe_id' => $subscribe_id,
                'social_media' => $social_media,
                'source' => 'keyword',
                'created_at' => date('Y-m-d H:i:s'),
            ));

            // Nothing queued for them should ever go out now.
            $ci->db->where('subscribe_id', $subscribe_id);
            $ci->db->where_in('state', array('pending', 'waiting_reentry', 'reentered'));
            $ci->db->update('reengage_recipient', array(
                'state' => 'skipped',
                'skip_reason' => 'opted_out',
            ));

            return true;
        } catch (Exception $e) {
            log_message('error', 'SPEC-19 reengage_check_optout: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('reengage_is_stop_keyword')) {
    /**
     * Exact-match stop words, Arabic and English. Normalised for the Arabic
     * forms customers actually type: hamza variants on alef, and the trailing
     * ta-marbuta / ha confusion.
     */
    function reengage_is_stop_keyword($text)
    {
        $text = trim(mb_strtolower($text, 'UTF-8'));
        $text = rtrim($text, " .!؟?\t\n");

        // Normalise alef variants so إيقاف / ايقاف / أيقاف all match.
        $text = str_replace(array('أ', 'إ', 'آ'), 'ا', $text);

        $stop = array(
            'ايقاف', 'إيقاف', 'ايقاف الرسائل', 'الغاء', 'إلغاء', 'الغاء الاشتراك',
            'توقف', 'كفايه', 'كفاية', 'بلاش',
            'stop', 'unsubscribe', 'cancel', 'quit', 'opt out', 'optout',
        );

        foreach ($stop as $word) {
            $word = str_replace(array('أ', 'إ', 'آ'), 'ا', $word);
            if ($text === $word) return true;
        }

        return false;
    }
}
