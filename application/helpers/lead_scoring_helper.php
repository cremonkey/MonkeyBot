<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-07 — Lead scoring. lead_add_score() is webhook-safe (never throws) and
 | anti-spams the frequent 'message_received' event to once per 10 minutes per subscriber.
 */

if (!function_exists('lead_add_score')) {
    /**
     * @param int    $subscriber_row_id  messenger_bot_subscriber.id
     * @param string $event_type         message_received|postback_click|price_question|add_to_cart|purchase|human_handoff
     * @param string $event_data         optional context
     */
    function lead_add_score($subscriber_row_id, $event_type, $event_data = null)
    {
        try {
            $subscriber_row_id = (int) $subscriber_row_id;
            if ($subscriber_row_id <= 0 || $event_type === '') return;
            $CI =& get_instance();
            $db = $CI->db;

            $sub = $db->select('id, user_id, lead_score')->from('messenger_bot_subscriber')->where('id', $subscriber_row_id)->get()->row_array();
            if (empty($sub)) return;
            $user_id = (int) $sub['user_id'];

            // rule: per-user override first, then global (user_id=0)
            $rule = $db->from('lead_scoring_rules')->where('event_type', $event_type)->where('status', '1')
                       ->where_in('user_id', array($user_id, 0))->order_by('user_id', 'DESC')->limit(1)->get()->row_array();
            if (empty($rule)) return;
            $points = (int) $rule['points'];

            // anti-spam frequent events
            if ($event_type === 'message_received') {
                $recent = $db->from('lead_scoring_events')->where('subscriber_id', $subscriber_row_id)
                             ->where('event_type', $event_type)->where('created_at >=', date('Y-m-d H:i:s', strtotime('-10 minutes')))
                             ->count_all_results();
                if ($recent > 0) return;
            }

            $db->insert('lead_scoring_events', array(
                'subscriber_id' => $subscriber_row_id, 'user_id' => $user_id,
                'event_type' => $event_type, 'points' => $points,
                'event_data' => $event_data !== null ? substr((string) $event_data, 0, 255) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            $db->set('lead_score', 'lead_score + ' . $points, false)->where('id', $subscriber_row_id)->update('messenger_bot_subscriber');
        } catch (Exception $e) {
            log_message('error', 'lead_add_score failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('lead_band')) {
    function lead_band($score)
    {
        $score = (int) $score;
        if ($score >= 50) return 'hot';
        if ($score >= 20) return 'warm';
        return 'cold';
    }
}

if (!function_exists('lead_band_badge')) {
    // Bootstrap badge class for a score.
    function lead_band_badge($score)
    {
        switch (lead_band($score)) {
            case 'hot': return 'badge badge-danger';
            case 'warm': return 'badge badge-warning';
            default: return 'badge badge-secondary';
        }
    }
}
