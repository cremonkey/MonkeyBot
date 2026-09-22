<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'helpers/channel_adapter_helper.php';

/**
 * Channel-agnostic outbound text messages, used by the follow-up engine,
 * coupon reminders and the daily digest. Mirrors how each channel's webhook
 * already replies:
 *   fb / ig : Graph me/messages with the page access token
 *   wa      : OpenWA gateway when the tenant has an active openwa_accounts row,
 *             otherwise WhatsApp Cloud API (account row = page_id)
 *   tg      : Telegram Bot API (account row = page_id)
 *   web     : row in livechat_messages; the widget's poller displays it
 * TikTok has no outbound DM API (partner-only), so 'tiktok' is unsupported.
 *
 * Phase 9.0 (ADR-002): this file is now a facade. Account lookup, secret
 * decryption and livechat logging stay here; the HTTP request for each channel
 * is made by its adapter in application/libraries/Channels (see
 * docs/channels/adapters.md). Signatures, return values and error strings are
 * unchanged.
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

        $result = channel_adapter($social_media)->sendText(\MonkeyBot\Channels\OutboundMessage::text(
            $subscribe_id, $text, array('page_access_token' => $page[0]['page_access_token']), $user_id
        ));
        if (!channel_send_meta_legacy_ok($result)) return array(false, $result->error);
        channel_log_message($ci, $user_id, $subscribe_id, $social_media, $text, $page[0]['page_id']);
        return array(true, '');
    }
}

if (!function_exists('channel_send_wa')) {
    function channel_send_wa($ci, $user_id, $subscribe_id, $text, $page_id)
    {
        $ci->load->helper('secret');

        // Prefer OpenWA gateway when configured (same channel as inbound OpenWA bots)
        if ($ci->db->table_exists('openwa_accounts')) {
            $owhere = array('user_id' => $user_id, 'status' => '1');
            if ($page_id !== '') {
                if ((int) $page_id > 0) $owhere['id'] = (int) $page_id;
                else $owhere['session_id'] = $page_id;
            }
            $oacc = $ci->basic->get_data('openwa_accounts', array('where' => $owhere), '', '', 1);
            if (!empty($oacc)) {
                $ci->load->library('Openwa_api');
                $result = channel_adapter('wa_openwa')->sendText(\MonkeyBot\Channels\OutboundMessage::text(
                    $subscribe_id, $text, array(
                        'base_url'   => $oacc[0]['base_url'],
                        'api_key'    => secret_decrypt($oacc[0]['api_key']),
                        'session_id' => $oacc[0]['session_id'],
                    ), $user_id
                ));
                // OpenWA account found: its result is final, a failure does NOT fall through to Cloud (as before)
                if (!$result->ok) return array(false, $result->error);
                channel_log_message($ci, $user_id, $subscribe_id, 'wa', $text, '', (int) $oacc[0]['id']);
                return array(true, '');
            }
        }

        // Fallback: Meta WhatsApp Cloud API
        $where = array('user_id' => $user_id, 'status' => '1');
        if ($page_id !== '' && (int) $page_id > 0) $where['id'] = (int) $page_id;
        $acc = $ci->basic->get_data('whatsapp_accounts', array('where' => $where), '', '', 1);
        if (empty($acc)) return array(false, 'no active whatsapp/OpenWA account');
        $ci->load->library('Whatsapp_api');
        $result = channel_adapter('wa_cloud')->sendText(\MonkeyBot\Channels\OutboundMessage::text(
            $subscribe_id, $text, array(
                'access_token'    => secret_decrypt($acc[0]['access_token']),
                'phone_number_id' => $acc[0]['phone_number_id'],
            ), $user_id
        ));
        if (!$result->ok) return array(false, $result->error);
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
        $result = channel_adapter('tg')->sendText(\MonkeyBot\Channels\OutboundMessage::text(
            $subscribe_id, $text, array('bot_token' => secret_decrypt($acc[0]['bot_token'])), $user_id
        ));
        if (!$result->ok) return array(false, $result->error);
        channel_log_message($ci, $user_id, $subscribe_id, 'tg', $text, '', (int) $acc[0]['id']);
        return array(true, '');
    }
}

if (!function_exists('channel_send_web')) {
    function channel_send_web($ci, $user_id, $subscribe_id, $text)
    {
        // webchat is pull-based: the widget polls livechat_messages, so a bot
        // row is delivered when the visitor still has (or reopens) the page.
        // The adapter's outbox IS that row, so there is no second log call.
        $result = channel_adapter('web')
            ->withOutbox(function ($m) use ($ci, $user_id, $subscribe_id, $text) {
                channel_log_message($ci, $user_id, $subscribe_id, 'web', $text);
            })
            ->sendText(\MonkeyBot\Channels\OutboundMessage::text($subscribe_id, $text, array(), $user_id));
        if (!$result->ok) {
            log_message('error', 'channel_send_text web: ' . $result->error);
            return array(false, $result->error);
        }
        return array(true, '');
    }
}

if (!function_exists('channel_send_meta_legacy_ok')) {
    /**
     * fb/ig success rule, kept exactly as it was: a send counts as delivered
     * unless Graph answered with an `error` object. That means a transport
     * failure or a non-JSON reply is still reported (and logged) as sent. The
     * adapter itself reports those as ok=false/retryable; flipping this facade
     * to trust $result->ok is a deliberate behaviour change left for its own
     * commit (see docs/channels/adapters.md, "Known legacy quirks").
     */
    function channel_send_meta_legacy_ok(\MonkeyBot\Channels\SendResult $result)
    {
        // Phase 9.0 decision (brief 51, no silent failure): trust the adapter. The
        // pre-9.0 rule counted a dead network, timeout or HTML 5xx as "sent" and wrote
        // it to the chat log, so the message vanished without anyone knowing. Now such
        // a send fails (retryable) and is not logged as delivered; callers already
        // treat $ok=false as a failed attempt. Name kept for call-site compatibility.
        return (bool) $result->ok;
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
