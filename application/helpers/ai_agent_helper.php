<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-18 — resolve the AI agent profile assigned to a given channel target.
 | Returns a profile row (array) or null. Channel identifier semantics differ:
 |   fb  -> $page_id is the DB page_table_id (Messenger_bot webhook)
 |   ig  -> $page_id is the FB page_id string (Instagram_reply)
 |   wa/tg -> $page_id is the channel account id
 |   web -> $page_id is 'webchat' (single widget per user)
 */

if (!function_exists('ai_resolve_profile')) {
    function ai_resolve_profile($user_id, $social_media, $page_id)
    {
        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0) return null;
            $CI =& get_instance();
            // cheap gate: skip entirely if this account uses no assignments
            $has = $CI->db->from('ai_agent_assignments')->where('user_id', $user_id)->count_all_results();
            if ($has === 0) return null;

            $channel = in_array($social_media, array('fb','ig','wa','tg','web')) ? $social_media : 'fb';
            $target = null;
            if ($channel === 'fb' || $channel === 'ig') {
                // normalize either page_table_id or fb page_id string to page_table_id
                $row = $CI->db->select('id')->from('facebook_rx_fb_page_info')
                    ->where('user_id', $user_id)
                    ->group_start()->where('id', $page_id)->or_where('page_id', $page_id)->group_end()
                    ->limit(1)->get()->row_array();
                if (empty($row)) return null;
                $target = (string) $row['id'];
            } elseif ($channel === 'web') {
                // $page_id carries the widget_key so each website widget resolves its own
                // agent; assignments are keyed on widget_key (target_id). When a caller
                // passes the legacy 'webchat' sentinel or nothing, fall back to the first
                // widget's key so old single-widget setups keep working.
                if (!empty($page_id) && $page_id !== 'webchat') {
                    $target = (string) $page_id;
                } else {
                    $row = $CI->db->select('widget_key')->from('webchat_settings')
                        ->where('user_id', $user_id)->order_by('id', 'ASC')->limit(1)->get()->row_array();
                    if (empty($row)) return null;
                    $target = (string) $row['widget_key'];
                }
            } else { // wa / tg
                $target = (string) $page_id;
            }

            $CI->db->from('ai_agent_assignments')->where('user_id', $user_id)->where('target_id', $target);
            if ($channel === 'fb' || $channel === 'ig') {
                // one page assignment covers both Messenger and Instagram; exact channel wins
                $CI->db->where_in('channel_type', array('fb','ig'))->order_by("channel_type='".$channel."'", 'DESC');
            } else {
                $CI->db->where('channel_type', $channel);
            }
            $assign = $CI->db->limit(1)->get()->row_array();
            if (empty($assign)) return null;

            $profile = $CI->db->from('ai_agent_profiles')
                ->where('id', (int) $assign['profile_id'])->where('user_id', $user_id)->where('status', '1')
                ->limit(1)->get()->row_array();
            return empty($profile) ? null : $profile;
        } catch (Exception $e) {
            log_message('error', 'ai_resolve_profile: ' . $e->getMessage());
            return null;
        }
    }
}
