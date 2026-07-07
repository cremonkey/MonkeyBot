<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Channel-agnostic outbound text messages, used by the follow-up engine,
 * coupon reminders and the daily digest. Mirrors how each channel's webhook
 * already replies:
 *   fb / ig : Graph me/messages with the page access token
 *   wa      : WhatsApp Cloud API via Whatsapp_api (account row = page_id)
 *   tg      : Telegram Bot API via Telegram_api (account row = page_id)
 *   web     : row in livechat_messages; the widget's poller displays it
 * TikTok has no outbound DM API (partner-only), so 'tiktok' is unsupported.
 *
 * $page_id carries whatever ai_conversation_history.page_id holds for that
 * channel: the FB page id for fb/ig, the account row id for wa/tg, and
 * 'webchat' for web.
 */

if (!function_exists('channel_send_text')) {
    /**
     * @return array [bool ok, string error]
     */
    function channel_send_text($user_id, $social_media, $subscribe_id, $text, $page_id = '')
    {
        $ci = &get_instance();
        $user_id = (int) $user_id;
        $text = trim((string) $text);
        if ($text === '' || $subscribe_id === '') return array(false, 'empty message or recipient');

        try {
            switch ($social_media) {
                case 'fb':
                case 'ig':
                    return channel_send_fb_ig($ci, $user_id, $social_media, $subscribe_id, $text, $page_id);
                case 'wa':
                    return channel_send_wa($ci, $user_id, $subscribe_id, $text, $page_id);
                case 'tg':
                    return channel_send_tg($ci, $user_id, $subscribe_id, $text, $page_id);
                case 'web':
                    return channel_send_web($ci, $user_id, $subscribe_id, $text);
                default:
                    return array(false, 'unsupported channel: ' . $social_media);
            }
        } catch (Exception $e) {
            log_message('error', 'channel_send_text ' . $social_media . ': ' . $e->getMessage());
            return array(false, $e->getMessage());
        }
    }
}

if (!function_exists('channel_send_fb_ig')) {
    function channel_send_fb_ig($ci, $user_id, $social_media, $subscribe_id, $text, $page_id)
    {
        // conversation history stores either the FB page_id or the table row id
        $ci->db->select('page_id, page_access_token')->from('facebook_rx_fb_page_info')->where('user_id', $user_id);
        if ($page_id !== '') {
            $ci->db->group_start()->where('page_id', $page_id);
            if (ctype_digit((string) $page_id)) $ci->db->or_where('id', (int) $page_id);
            $ci->db->group_end();
        }
        $page = $ci->db->limit(1)->get()->result_array();
        if (empty($page) || empty($page[0]['page_access_token'])) return array(false, 'page token not found');

        $payload = json_encode(array(
            'recipient'      => array('id' => $subscribe_id),
            'messaging_type' => 'RESPONSE',
            'message'        => array('text' => $text),
        ));
        // v2.6 endpoint = byte-for-byte the same path Home::send_reply uses for
        // all webhook bot replies (fb + ig DMs), so follow-ups inherit exactly
        // the access level that is proven to work on this app
        $ch = curl_init('https://graph.facebook.com/v2.6/me/messages?access_token=' . urlencode($page[0]['page_access_token']));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $raw = curl_exec($ch);
        curl_close($ch);
        $res = json_decode((string) $raw, true);
        if (!empty($res['error'])) {
            return array(false, 'graph: ' . ($res['error']['message'] ?? 'unknown') . ' (code ' . ($res['error']['code'] ?? '?') . ')');
        }
        channel_log_message($ci, $user_id, $subscribe_id, $social_media, $text, $page[0]['page_id']);
        return array(true, '');
    }
}

if (!function_exists('channel_send_wa')) {
    function channel_send_wa($ci, $user_id, $subscribe_id, $text, $page_id)
    {
        $where = array('user_id' => $user_id, 'status' => '1');
        if ($page_id !== '' && (int) $page_id > 0) $where['id'] = (int) $page_id;
        $acc = $ci->basic->get_data('whatsapp_accounts', array('where' => $where), '', '', 1);
        if (empty($acc)) return array(false, 'no active whatsapp account');
        $ci->load->helper('secret');
        $ci->load->library('Whatsapp_api');
        $token = secret_decrypt($acc[0]['access_token']);
        $res = $ci->whatsapp_api->send_text($token, $acc[0]['phone_number_id'], $subscribe_id, $text);
        if (empty($res['messages'])) {
            return array(false, 'wa: ' . json_encode(isset($res['error']) ? $res['error'] : $res));
        }
        channel_log_message($ci, $user_id, $subscribe_id, 'wa', $text, '', (int) $acc[0]['id']);
        return array(true, '');
    }
}

if (!function_exists('channel_send_tg')) {
    function channel_send_tg($ci, $user_id, $subscribe_id, $text, $page_id)
    {
        $where = array('user_id' => $user_id, 'status' => '1');
        if ($page_id !== '' && (int) $page_id > 0) $where['id'] = (int) $page_id;
        $acc = $ci->basic->get_data('telegram_accounts', array('where' => $where), '', '', 1);
        if (empty($acc)) return array(false, 'no active telegram account');
        $ci->load->helper('secret');
        $ci->load->library('Telegram_api');
        $token = secret_decrypt($acc[0]['bot_token']);
        $res = $ci->telegram_api->send_message($token, $subscribe_id, $text);
        if (empty($res['ok'])) {
            return array(false, 'tg: ' . json_encode($res));
        }
        channel_log_message($ci, $user_id, $subscribe_id, 'tg', $text, '', (int) $acc[0]['id']);
        return array(true, '');
    }
}

if (!function_exists('channel_send_web')) {
    function channel_send_web($ci, $user_id, $subscribe_id, $text)
    {
        // webchat is pull-based: the widget polls livechat_messages, so a bot
        // row is delivered when the visitor still has (or reopens) the page
        channel_log_message($ci, $user_id, $subscribe_id, 'web', $text);
        return array(true, '');
    }
}

if (!function_exists('channel_log_message')) {
    function channel_log_message($ci, $user_id, $subscribe_id, $platform, $text, $fb_page_id = '', $page_table_id = 0)
    {
        $ci->basic->insert_data('livechat_messages', array(
            'user_id' => $user_id, 'subscriber_id' => $subscribe_id,
            'page_table_id' => $page_table_id, 'fb_page_id' => $fb_page_id,
            'sender' => 'bot', 'platform' => $platform, 'agent_name' => 'Bot',
            'message_content' => $text, 'conversation_time' => date('Y-m-d H:i:s'),
            'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0',
        ));
    }
}
