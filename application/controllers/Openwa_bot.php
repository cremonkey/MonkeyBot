<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Messenger_bot.php");

/**
 * OpenWA channel — inbound webhook + admin UI.
 * Reuses Messenger_bot::send_message_bot_reply so keyword / no-match / AI template
 * paths match Facebook & Instagram (media_type='wa').
 *
 * Mapping:
 *   messenger_bot.page_id     = openwa_accounts.id
 *   messenger_bot.fb_page_id  = openwa_accounts.session_id
 *   messenger_bot.media_type  = 'wa'
 *   subscriber.subscribe_id   = phone digits
 *   subscriber.social_media   = 'wa'
 *   subscriber.page_table_id  = openwa_accounts.id
 */
class Openwa_bot extends Messenger_bot
{
    public function __construct()
    {
        // Always boot Home only — Messenger_bot::__construct would block our
        // public webhook and also require FB module 199 for the admin UI.
        Home::__construct();
        $this->load->library('Openwa_api');
        $this->load->helper('secret');

        $seg = $this->uri->segment(2);
        if ($seg === 'webhook') return;

        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->user_id = $this->uid;
    }

    public function index()
    {
        $accounts = $this->basic->get_data('openwa_accounts', array('where'=>array('user_id'=>$this->uid)), '', '', '', '', 'id DESC');
        // Attach live OpenWA session health (stale sessions show ready but cannot send)
        foreach ($accounts as &$acc) {
            $acc['owa_status'] = '';
            $acc['owa_last_active'] = '';
            $acc['owa_healthy'] = null;
            $acc['nomatch_bot_id'] = 0;
            $nm = $this->basic->get_data('messenger_bot', array(
                'where' => array(
                    'page_id' => (int)$acc['id'],
                    'media_type' => 'wa',
                    'keyword_type' => 'no match',
                    'user_id' => $this->uid,
                ),
            ), array('id'), '', 1);
            if (!empty($nm[0]['id'])) $acc['nomatch_bot_id'] = (int)$nm[0]['id'];
            try {
                $key = secret_decrypt($acc['api_key']);
                $sess = $this->openwa_api->get_session($acc['base_url'], $key, $acc['session_id']);
                if (!empty($sess['id'])) {
                    $acc['owa_status'] = (string)($sess['status'] ?? '');
                    $acc['owa_last_active'] = (string)($sess['lastActive'] ?? ($sess['last_active'] ?? ''));
                    $last_ts = $acc['owa_last_active'] !== '' ? strtotime($acc['owa_last_active']) : 0;
                    // Consider unhealthy if last activity older than 2 days (engine zombie)
                    $acc['owa_healthy'] = ($acc['owa_status'] === 'ready' && $last_ts > (time() - 2 * 86400));
                } else {
                    $acc['owa_healthy'] = false;
                    $acc['owa_status'] = 'unreachable';
                }
            } catch (Exception $e) {
                $acc['owa_healthy'] = false;
                $acc['owa_status'] = 'error';
            }
        }
        unset($acc);

        $data['accounts'] = $accounts;
        $data['webhook_base'] = base_url('openwa_bot/webhook/');
        $data['page_title'] = 'OpenWA WhatsApp';
        $data['default_base_url'] = 'https://wa.cremonkey.com';
        $data['body'] = 'admin/openwa_bot/index';
        $this->_viewcontroller($data);
    }

    public function save()
    {
        try {
            $this->csrf_token_check();
            $label = strip_tags((string)$this->input->post('label', true));
            $base_url = rtrim(strip_tags((string)$this->input->post('base_url', true)), '/');
            $api_key = trim((string)$this->input->post('api_key', true));
            $session_id = trim(strip_tags((string)$this->input->post('session_id', true)));
            $ai_enabled = $this->input->post('ai_enabled', true) == '1' ? '1' : '0';
            $bot_enabled = $this->input->post('bot_enabled', true) == '1' ? '1' : '0';

            if ($base_url === '') $base_url = 'https://wa.cremonkey.com';
            if ($api_key === '' || $session_id === '') {
                $this->session->set_flashdata('error', 'API key and Session ID are required');
                redirect('openwa_bot');
                return;
            }

            // Validate session against OpenWA
            $sess = $this->openwa_api->get_session($base_url, $api_key, $session_id);
            if (empty($sess['id']) && (int)($sess['_http'] ?? 0) !== 200) {
                $this->session->set_flashdata('error', 'Could not reach OpenWA session. Check base URL, API key, and session ID. HTTP=' . ($sess['_http'] ?? '?'));
                redirect('openwa_bot');
                return;
            }

            $display_phone = isset($sess['phone']) ? (string)$sess['phone'] : '';
            $session_name = isset($sess['name']) ? (string)$sess['name'] : $label;
            $final_label = $label !== '' ? $label : ($session_name ?: 'OpenWA');

            // Upsert: session_id is UNIQUE — re-saving the same OpenWA session must update, not 500
            $existing = $this->basic->get_data('openwa_accounts', array('where'=>array('session_id'=>$session_id)), '', '', 1);
            $is_update = !empty($existing);
            if ($is_update) {
                $acc_id = (int)$existing[0]['id'];
                // Keep existing webhook secret unless missing (so OpenWA signature still validates)
                $webhook_secret = !empty($existing[0]['webhook_secret'])
                    ? $existing[0]['webhook_secret']
                    : bin2hex(random_bytes(16));
                $this->basic->update_data('openwa_accounts', array('id'=>$acc_id), array(
                    'user_id' => $this->uid,
                    'label' => $final_label,
                    'base_url' => $base_url,
                    'api_key' => secret_encrypt($api_key),
                    'session_name' => $session_name,
                    'display_phone' => $display_phone,
                    'webhook_secret' => $webhook_secret,
                    'bot_enabled' => $bot_enabled,
                    'ai_enabled' => $ai_enabled,
                    'no_match_found_reply' => 'enabled',
                    'status' => '1',
                ));
            } else {
                $webhook_secret = bin2hex(random_bytes(16));
                $this->basic->insert_data('openwa_accounts', array(
                    'user_id' => $this->uid,
                    'label' => $final_label,
                    'base_url' => $base_url,
                    'api_key' => secret_encrypt($api_key),
                    'session_id' => $session_id,
                    'session_name' => $session_name,
                    'display_phone' => $display_phone,
                    'webhook_secret' => $webhook_secret,
                    'bot_enabled' => $bot_enabled,
                    'ai_enabled' => $ai_enabled,
                    'no_match_found_reply' => 'enabled',
                    'status' => '1',
                    'created_at' => date('Y-m-d H:i:s'),
                ));
                $acc_id = (int)$this->db->insert_id();
            }

            // Register webhook on OpenWA → monkeybot (skip if already linked)
            $callback = base_url('openwa_bot/webhook/' . $acc_id);
            $wh_id = $is_update ? ($existing[0]['openwa_webhook_id'] ?? null) : null;
            if (empty($wh_id)) {
                $wh = $this->openwa_api->create_webhook($base_url, $api_key, $session_id, $callback, array('message.received'), $webhook_secret);
                $wh_id = $wh['id'] ?? ($wh['data']['id'] ?? null);
                if ($wh_id) {
                    $this->basic->update_data('openwa_accounts', array('id'=>$acc_id), array('openwa_webhook_id'=>$wh_id));
                }
            }

            // Seed a default "no match" AI bot so WA follows FB/IG no-match path out of the box
            $this->seed_default_bots($acc_id, $session_id, $this->uid);

            $msg = $is_update ? 'OpenWA account updated.' : 'OpenWA connected.';
            if (!$wh_id) $msg .= ' Warning: webhook auto-register failed — set callback manually to ' . $callback;
            else $msg .= ' Webhook ready.';
            $this->session->set_flashdata('success', $msg);
            redirect('openwa_bot');
        } catch (Exception $e) {
            log_message('error', 'openwa_bot/save: ' . $e->getMessage());
            $this->session->set_flashdata('error', 'Save failed: ' . $e->getMessage());
            redirect('openwa_bot');
        }
    }

    public function delete($id = 0)
    {
        if (!hash_equals((string)$this->session->userdata('csrf_token_session'), (string)$this->input->get('t'))) {
            show_error('Invalid token', 403);
        }
        $acc = $this->basic->get_data('openwa_accounts', array('where'=>array('id'=>(int)$id, 'user_id'=>$this->uid)));
        if (!empty($acc)) {
            $a = $acc[0];
            if (!empty($a['openwa_webhook_id'])) {
                $key = secret_decrypt($a['api_key']);
                $this->openwa_api->delete_webhook($a['base_url'], $key, $a['session_id'], $a['openwa_webhook_id']);
            }
            // Remove WA bots tied to this account
            $this->db->where(array('page_id'=>(int)$id, 'media_type'=>'wa', 'user_id'=>$this->uid))->delete('messenger_bot');
            $this->db->where(array('id'=>(int)$id, 'user_id'=>$this->uid))->delete('openwa_accounts');
        }
        redirect('openwa_bot');
    }

    /**
     * Quick keyword bot create (same messenger_bot rows FB/IG use).
     */
    public function save_keyword_bot()
    {
        $this->csrf_token_check();
        $account_id = (int)$this->input->post('account_id', true);
        $keywords = trim((string)$this->input->post('keywords', true));
        $reply_text = trim((string)$this->input->post('reply_text', true));
        $acc = $this->basic->get_data('openwa_accounts', array('where'=>array('id'=>$account_id, 'user_id'=>$this->uid)));
        if (empty($acc) || $keywords === '' || $reply_text === '') {
            $this->session->set_flashdata('error', 'Account, keywords, and reply text are required');
            redirect('openwa_bot');
            return;
        }
        $session_id = $acc[0]['session_id'];
        $message = json_encode(array(array(
            'recipient' => array('id' => 'replace_id'),
            'message' => array(
                'template_type' => 'text',
                'typing_on_settings' => 'off',
                'delay_in_reply' => '0',
                'text' => $reply_text,
            ),
        )));
        $this->basic->insert_data('messenger_bot', $this->wa_bot_row_defaults(array(
            'user_id' => $this->uid,
            'page_id' => $account_id,
            'fb_page_id' => $session_id,
            'bot_name' => 'WA keyword: ' . mb_substr($keywords, 0, 40),
            'keywords' => $keywords,
            'keyword_type' => 'reply',
            'message' => $message,
            'media_type' => 'wa',
            'status' => '1',
            'trigger_matching_type' => 'string',
        )));
        $this->session->set_flashdata('success', 'Keyword bot saved — same engine as Facebook/Instagram');
        redirect('openwa_bot');
    }

    /** Toggle Live/Stop for a WA keyword bot owned by this OpenWA account. */
    public function change_bot_state()
    {
        header('Content-Type: application/json');
        if (!$this->csrf_token_check(true)) {
            echo json_encode(array('status'=>'error', 'message'=>'CSRF Token Mismatch!'));
            return;
        }
        $table_id = (int)$this->input->post('table_id', true);
        $result = $this->toggle_wa_bot_status($table_id);
        echo json_encode($result);
    }

    /** Delete a classic WA keyword bot owned by this OpenWA account (AJAX). */
    public function delete_bot()
    {
        if (!$this->csrf_token_check(true)) {
            echo '0';
            return;
        }
        $id = (int)$this->input->post('id', true);
        echo $this->remove_wa_bot_row($id) ? '1' : '0';
    }

    /** Non-AJAX Stop/Start — used by Bot Settings action buttons. */
    public function toggle_bot($id = 0)
    {
        $t = (string)$this->input->get('t');
        $session_t = (string)$this->session->userdata('csrf_token_session');
        if ($t === '' || $session_t === '' || !hash_equals($session_t, $t)) {
            $this->session->set_flashdata('error', 'Invalid token');
            redirect('openwa_bot');
            return;
        }
        $id = (int)$id;
        $result = $this->toggle_wa_bot_status($id);
        if (($result['status'] ?? '') === 'success') {
            $this->session->set_flashdata('success', $result['message']);
        } else {
            $this->session->set_flashdata('error', $result['message'] ?? 'Failed');
        }
        $bot = $this->basic->get_data('messenger_bot', array('where'=>array('id'=>$id)), array('page_id'), '', 1);
        $page_id = !empty($bot[0]['page_id']) ? (int)$bot[0]['page_id'] : 1;
        redirect('messenger_bot/bot_settings/'.$page_id.'/1?media_type=wa');
    }

    /** Non-AJAX Delete — used by Bot Settings action buttons. */
    public function remove_bot($id = 0)
    {
        $t = (string)$this->input->get('t');
        $session_t = (string)$this->session->userdata('csrf_token_session');
        if ($t === '' || $session_t === '' || !hash_equals($session_t, $t)) {
            $this->session->set_flashdata('error', 'Invalid token');
            redirect('openwa_bot');
            return;
        }
        $id = (int)$id;
        $bot = $this->basic->get_data('messenger_bot', array('where'=>array('id'=>$id, 'media_type'=>'wa')), array('page_id'), '', 1);
        $page_id = !empty($bot[0]['page_id']) ? (int)$bot[0]['page_id'] : 1;
        if ($this->remove_wa_bot_row($id)) {
            $this->session->set_flashdata('success', 'Bot reply deleted.');
        } else {
            $this->session->set_flashdata('error', 'Could not delete bot reply.');
        }
        redirect('messenger_bot/bot_settings/'.$page_id.'/1?media_type=wa');
    }

    private function toggle_wa_bot_status($table_id)
    {
        $bot = $this->basic->get_data('messenger_bot', array('where'=>array('id'=>(int)$table_id, 'media_type'=>'wa')), '', '', 1);
        if (empty($bot)) {
            return array('status'=>'error', 'message'=>'Bot not found');
        }
        $acc = $this->basic->get_data('openwa_accounts', array(
            'where'=>array('id'=>(int)$bot[0]['page_id'], 'user_id'=>$this->uid)
        ), array('id'), '', 1);
        if (empty($acc)) {
            return array('status'=>'error', 'message'=>'Access denied');
        }
        $new_state = ($bot[0]['status'] == '1') ? '0' : '1';
        $this->basic->update_data('messenger_bot', array('id'=>(int)$table_id), array(
            'status' => $new_state,
            'user_id' => $this->uid,
        ));
        return array('status'=>'success', 'message'=>$this->lang->line("State has successfully changed."));
    }

    private function remove_wa_bot_row($id)
    {
        $bot = $this->basic->get_data('messenger_bot', array('where'=>array('id'=>(int)$id, 'media_type'=>'wa')), '', '', 1);
        if (empty($bot)) return false;
        $acc = $this->basic->get_data('openwa_accounts', array(
            'where'=>array('id'=>(int)$bot[0]['page_id'], 'user_id'=>$this->uid)
        ), array('id'), '', 1);
        if (empty($acc)) return false;
        if (($bot[0]['visual_flow_type'] ?? 'general') !== 'general') return false;
        $this->basic->delete_data('messenger_bot', array('id'=>(int)$id));
        return true;
    }

    /**
     * Public webhook from OpenWA (message.received).
     */
    public function webhook($account_id = 0)
    {
        $this->output->set_content_type('application/json');
        $raw = file_get_contents('php://input');
        try {
            $acc = $this->basic->get_data('openwa_accounts', array('where'=>array('id'=>(int)$account_id, 'status'=>'1')));
            if (empty($acc)) {
                $this->output->set_status_header(404);
                echo json_encode(array('ok'=>false, 'reason'=>'account'));
                return;
            }
            $acc = $acc[0];

            // Signature check when secret configured
            $sig = isset($_SERVER['HTTP_X_OPENWA_SIGNATURE']) ? $_SERVER['HTTP_X_OPENWA_SIGNATURE'] : '';
            if (!empty($acc['webhook_secret']) && !$this->openwa_api->verify_signature($raw, $sig, $acc['webhook_secret'])) {
                $this->output->set_status_header(403);
                echo json_encode(array('ok'=>false, 'reason'=>'signature'));
                return;
            }

            $body = json_decode($raw, true);
            if (!is_array($body)) {
                echo json_encode(array('ok'=>true));
                return;
            }

            $event = $body['event'] ?? '';
            if ($event !== '' && $event !== 'message.received') {
                echo json_encode(array('ok'=>true, 'ignored'=>$event));
                return;
            }

            $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
            if (!empty($data['fromMe']) || !empty($data['isGroup'])) {
                echo json_encode(array('ok'=>true, 'ignored'=>'fromMe_or_group'));
                return;
            }

            $from = $data['from'] ?? ($data['chatId'] ?? '');
            if ($from === '') {
                echo json_encode(array('ok'=>true));
                return;
            }

            // Prefer explicit phone when OpenWA provides it; otherwise keep LID jid for replies
            $reply_chat_id = $from;
            if (!empty($data['contact']['number'])) {
                $reply_chat_id = (string)$data['contact']['number'];
            } elseif (!empty($data['author']) && stripos((string)$data['author'], '@lid') === false) {
                $reply_chat_id = (string)$data['author'];
            }

            $subscribe_id = $this->openwa_api->to_subscribe_id($from);
            $text = isset($data['body']) ? (string)$data['body'] : '';
            $push_name = '';
            if (!empty($data['contact']['pushName'])) $push_name = (string)$data['contact']['pushName'];
            elseif (!empty($data['contact']['name'])) $push_name = (string)$data['contact']['name'];

            // ACK early so OpenWA does not retry while we process
            echo json_encode(array('ok'=>true));
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();

            $this->log_msg($acc['user_id'], $subscribe_id, (int)$acc['id'], 'user', $text !== '' ? $text : '[non-text]');

            if ($acc['bot_enabled'] !== '1') return;

            $subscriber_info = $this->find_or_create_wa_subscriber($acc, $subscribe_id, $push_name, $reply_chat_id);

            // Human handoff pause
            $is_paused = isset($subscriber_info[0]['bot_paused_until'])
                && $subscriber_info[0]['bot_paused_until'] !== null
                && $subscriber_info[0]['bot_paused_until'] > date('Y-m-d H:i:s');
            if ($is_paused) return;

            if ($text === '') return;

            // Same opt-out hook as FB/IG text path
            $this->load->helper('reengage_hook');
            if (!empty($acc['user_id'])) {
                reengage_check_optout($acc['user_id'], $subscribe_id, 'wa', $text);
            }

            // Always reply to the WhatsApp jid we received (LID or phone)
            $send_to = $reply_chat_id !== '' ? $reply_chat_id : $from;

            $matched = $this->run_keyword_bots($acc, $send_to, $subscriber_info, $text);
            if ($matched) return;

            // No-match bot (same as FB/IG)
            if (($acc['no_match_found_reply'] ?? 'enabled') === 'enabled') {
                $matched = $this->run_no_match_bot($acc, $send_to, $subscriber_info, $text);
                if ($matched) return;
            }

            // AI fallback (like previous Whatsapp_bot) when no messenger_bot row handled it
            if ($acc['ai_enabled'] === '1') {
                $reply = $this->get_ai_reply_open_ai('', $text, $acc['user_id'], $acc['session_id'], $subscribe_id, 'wa');
                $reply_text = $reply['choices'][0]['text'] ?? '';
                if ($reply_text !== '') {
                    $api_key = secret_decrypt($acc['api_key']);
                    $this->openwa_api->send_text($acc['base_url'], $api_key, $acc['session_id'], $send_to, $reply_text);
                    $this->log_msg($acc['user_id'], $subscribe_id, (int)$acc['id'], 'bot', $reply_text);
                }
            }
        } catch (Exception $e) {
            log_message('error', 'openwa webhook: ' . $e->getMessage());
            // already echoed ok when possible
        }
    }

    // ─── Bot engine (mirrors Messenger_bot::webhook_callback_main text branch) ───

    private function run_keyword_bots($acc, $sender_id, $subscriber_info, $messages)
    {
        $bots = $this->load_wa_bots($acc, false);
        if (empty($bots)) return false;

        list($single, $two, $three) = $this->split_message_words($messages);

        foreach ($bots as $value) {
            if (($value['keyword_type'] ?? '') === 'no match') continue;
            $cam_keywords_array = array_filter(array_map('trim', explode(',', (string)$value['keywords'])));
            foreach ($cam_keywords_array as $cam_keywords) {
                if (function_exists('iconv') && function_exists('mb_detect_encoding')) {
                    $encoded_word = mb_detect_encoding($cam_keywords);
                    if ($encoded_word) {
                        $cam_keywords = strtolower(iconv($encoded_word, 'UTF-8//TRANSLIT', $cam_keywords));
                        $cam_keywords = trim($cam_keywords);
                    }
                }

                if (($value['trigger_matching_type'] ?? 'string') === 'exact') {
                    $temp = explode(' ', $cam_keywords);
                    $search = count($temp) === 1 ? $single : (count($temp) === 2 ? $two : $three);
                    $matches = in_array($cam_keywords, $search);
                } else {
                    $matches = stripos($messages, trim($cam_keywords));
                }

                if ($matches !== false) {
                    $this->send_message_bot_reply($value, $sender_id, $subscriber_info, $acc['session_id'], '', $messages);
                    $this->apply_bot_side_effects($value, $sender_id, $subscriber_info, $acc, $messages);
                    return true;
                }
            }
        }
        return false;
    }

    private function run_no_match_bot($acc, $sender_id, $subscriber_info, $messages)
    {
        $bots = $this->load_wa_bots($acc, true);
        if (empty($bots)) return false;
        $this->send_message_bot_reply($bots[0], $sender_id, $subscriber_info, $acc['session_id'], '', $messages);
        return true;
    }

    private function load_wa_bots($acc, $no_match_only)
    {
        // Load all active WA bots for this OpenWA account — includes classic
        // keyword rows AND Visual Flow Builder rows (visual_flow_type='flow').
        $this->db->select('messenger_bot.*');
        $this->db->from('messenger_bot');
        $this->db->where('messenger_bot.page_id', (int)$acc['id']);
        $this->db->where('messenger_bot.status', '1');
        $this->db->where('messenger_bot.media_type', 'wa');
        $this->db->where('messenger_bot.is_template', '0');
        if ($no_match_only) {
            $this->db->where('messenger_bot.keyword_type', 'no match');
            $this->db->order_by('messenger_bot.id', 'ASC');
            $this->db->limit(1);
        } else {
            // Text keyword matching: reply keywords from classic + visual flows
            $this->db->where('messenger_bot.keyword_type !=', 'no match');
            $this->db->where("TRIM(IFNULL(messenger_bot.keywords,'')) != ''", null, false);
            $this->db->order_by('messenger_bot.id', 'DESC');
        }
        $rows = $this->db->get()->result_array();

        // Inject OpenWA send credentials the same way FB injects page_access_token
        foreach ($rows as &$r) {
            $r['page_access_token'] = 'openwa:' . $acc['id'];
            $r['enable_mark_seen'] = '0';
            $r['enbale_type_on'] = '0';
            $r['reply_delay_time'] = 0;
            $r['openwa_account_id'] = (int)$acc['id'];
            $r['openwa_base_url'] = $acc['base_url'];
            $r['openwa_api_key'] = secret_decrypt($acc['api_key']);
            $r['openwa_session_id'] = $acc['session_id'];
        }
        return $rows;
    }

    private function split_message_words($messages)
    {
        $single = array();
        $two = array();
        $three = array();
        if (!(function_exists('iconv') && function_exists('mb_detect_encoding'))) {
            return array($single, $two, $three);
        }
        $encoded = mb_detect_encoding($messages);
        $utf = $encoded ? iconv($encoded, 'UTF-8//TRANSLIT', $messages) : $messages;
        $words = mb_split(' ', $utf);
        foreach ($words as $w) {
            $single[] = strtolower(trim($w, ",.!'/#* <>$&%@()[];?^+-=~`\""));
        }
        $single = array_values(array_filter($single));
        $n = count($single);
        for ($i = 0; $i < $n - 1; $i++) {
            if (isset($single[$i], $single[$i + 1])) $two[] = trim($single[$i] . ' ' . $single[$i + 1]);
            if (isset($single[$i + 2])) $three[] = trim($single[$i] . ' ' . $single[$i + 1] . ' ' . $single[$i + 2]);
        }
        return array($single, array_filter($two), array_filter($three));
    }

    private function apply_bot_side_effects($value, $sender_id, $subscriber_info, $acc, $messages)
    {
        $drip_campaign_id = $value['drip_campaign_id'] ?? 0;
        if ($drip_campaign_id > 0) {
            $this->assign_drip_messaging_id('custom', '0', $subscriber_info[0]['page_table_id'], $subscriber_info[0]['subscribe_id'], $drip_campaign_id);
        }
        if (!empty($value['team_assign_user_id'])) {
            $this->assign_conversation_to_team_member($sender_id, $value['team_assign_user_id'], $subscriber_info[0]['page_table_id'], $value['team_assign_role_id'] ?? '', 'wa');
        }
        $remove_drip = $value['remove_sequence_campaign_id'] ?? 0;
        if ($remove_drip > 0) {
            $this->remove_drip_messaging_id('custom', '0', $subscriber_info[0]['page_table_id'], $subscriber_info[0]['subscribe_id'], $remove_drip);
        }
        $label_ids = $value['broadcaster_labels'] ?? '';
        if ($label_ids !== '') {
            $this->multiple_assign_label($sender_id, $acc['session_id'], $label_ids, 'wa', $subscriber_info[0]['id']);
        }
        $remove_labels = $value['remove_label_ids'] ?? '';
        if ($remove_labels !== '') {
            $this->multiple_remove_label($sender_id, $acc['session_id'], $remove_labels, 'wa', $subscriber_info[0]['id']);
        }
        $this->update_subscriber_last_interaction($sender_id, date('Y-m-d H:i:s'), '0');
    }

    private function find_or_create_wa_subscriber($acc, $subscribe_id, $push_name, $reply_chat_id = '')
    {
        $existing = $this->basic->get_data('messenger_bot_subscriber', array(
            'where' => array('subscribe_id'=>$subscribe_id, 'social_media'=>'wa', 'page_table_id'=>(int)$acc['id']),
        ), '', '', 1);

        $chat_for_reply = $reply_chat_id !== '' ? $reply_chat_id : $subscribe_id;
        // Persist full jid (…@lid / …@c.us) in phone_number for reliable OpenWA replies
        if (!empty($existing)) {
            if ($chat_for_reply !== '' && ($existing[0]['phone_number'] ?? '') !== $chat_for_reply) {
                $this->basic->update_data('messenger_bot_subscriber', array('id'=>$existing[0]['id']), array(
                    'phone_number' => $chat_for_reply,
                    'last_subscriber_interaction_time' => date('Y-m-d H:i:s'),
                ));
                $existing[0]['phone_number'] = $chat_for_reply;
            }
            return $existing;
        }

        $parts = preg_split('/\s+/', trim($push_name), 2);
        $first = $parts[0] ?? '';
        $last = $parts[1] ?? '';
        $now = date('Y-m-d H:i:s');
        $row = array(
            'user_id' => $acc['user_id'],
            'page_id' => $acc['session_id'],
            'page_table_id' => (int)$acc['id'],
            'subscribe_id' => $subscribe_id,
            'client_thread_id' => '',
            'contact_group_id' => '',
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $push_name !== '' ? $push_name : $subscribe_id,
            'profile_pic' => '',
            'gender' => '',
            'locale' => '',
            'timezone' => '',
            'unavailable' => '0',
            'last_error_message' => '',
            'refferer_id' => '',
            'refferer_source' => '',
            'refferer_uri' => '',
            'subscribed_at' => $now,
            'unsubscribed_at' => '0000-00-00 00:00:00',
            'link' => '',
            'is_image_download' => '0',
            'image_path' => '',
            'status' => '1',
            'is_bot_subscriber' => '1',
            'is_email_unsubscriber' => '0',
            'is_imported' => '0',
            'is_updated_name' => '1',
            'last_name_update_time' => $now,
            'email' => '',
            'phone_number' => $chat_for_reply,
            'user_location' => '',
            'birthdate' => '0000-00-00',
            'last_subscriber_interaction_time' => $now,
            'unseen_count' => 0,
            'is_archived' => '0',
            'is_24h_1_sent' => '0',
            'woocommerce_drip_campaign_id' => 0,
            'wc_user_id' => 0,
            'password' => '',
            'subscriber_type' => 'messenger',
            'store_id' => 0,
            'social_media' => 'wa',
            'cron_lock' => '0',
            'permission' => '1',
        );
        $this->basic->insert_data('messenger_bot_subscriber', $row);
        $row['id'] = $this->db->insert_id();
        return array($row);
    }

    private function seed_default_bots($account_id, $session_id, $user_id)
    {
        // Default no-match → AI (text_from=AI) so empty keyword setups still reply
        $ai_message = json_encode(array(array(
            'recipient' => array('id' => 'replace_id'),
            'message' => array(
                'template_type' => 'text',
                'typing_on_settings' => 'off',
                'delay_in_reply' => '0',
                'text' => '',
                'text_from' => 'AI',
            ),
        )));
        $exists = $this->basic->get_data('messenger_bot', array(
            'where' => array('page_id'=>$account_id, 'media_type'=>'wa', 'keyword_type'=>'no match'),
        ), array('id'), '', 1);
        if (empty($exists)) {
            $this->basic->insert_data('messenger_bot', $this->wa_bot_row_defaults(array(
                'user_id' => $user_id,
                'page_id' => $account_id,
                'fb_page_id' => $session_id,
                'bot_name' => 'OpenWA No Match (AI)',
                'keywords' => '',
                'keyword_type' => 'no match',
                'message' => $ai_message,
                'media_type' => 'wa',
                'status' => '1',
                'trigger_matching_type' => 'string',
            )));
        }
    }

    /** Fill NOT NULL messenger_bot columns that FB UI normally sets. */
    private function wa_bot_row_defaults($row)
    {
        $defaults = array(
            'template_type' => 'text',
            'bot_type' => 'keyword',
            'conditions' => '',
            'message_condition_false' => '',
            'buttons' => '',
            'images' => '',
            'audio' => '',
            'video' => '',
            'file' => '',
            'postback_id' => '',
            'is_template' => '0',
            'broadcaster_labels' => '',
            'drip_campaign_id' => 0,
            'remove_label_ids' => '',
            'remove_sequence_campaign_id' => 0,
            'team_assign_role_id' => 0,
            'team_assign_user_id' => 0,
            'visual_flow_type' => 'general',
            'visual_flow_campaign_id' => 0,
        );
        return array_merge($defaults, $row);
    }

    private function log_msg($user_id, $wa_id, $account_id, $sender, $content)
    {
        $this->basic->insert_data('livechat_messages', array(
            'user_id' => $user_id,
            'subscriber_id' => $wa_id,
            'page_table_id' => $account_id,
            'fb_page_id' => '',
            'sender' => $sender,
            'platform' => 'wa',
            'agent_name' => ($sender === 'bot' ? 'Bot' : ''),
            'message_content' => $content,
            'conversation_time' => date('Y-m-d H:i:s'),
            'fb_message_id' => '',
            'message_status' => 'sent',
            'from_business_suite' => '0',
        ));
    }
}
