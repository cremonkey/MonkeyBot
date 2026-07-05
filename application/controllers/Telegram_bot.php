<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-15 — Telegram bot channel. webhook() is PUBLIC (verified via secret token header);
 * admin methods require login.
 */
class Telegram_bot extends Home
{
    public function __construct()
    {
        parent::__construct();
        $seg = $this->uri->segment(2);
        if ($seg !== 'webhook') {
            if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
            $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        }
        $this->load->library('Telegram_api');
    }

    public function index()
    {
        $data['accounts'] = $this->basic->get_data('telegram_accounts', ['where'=>['user_id'=>$this->uid]], '', '', '', '', 'id DESC');
        $data['webhook_base'] = base_url('telegram_bot/webhook/');
        $data['page_title'] = 'Telegram Bot';
        $data['body'] = 'admin/telegram_bot/index';
        $this->_viewcontroller($data);
    }

    public function save()
    {
        $this->csrf_token_check();
        $token = trim((string) $this->input->post('bot_token', true));
        $ai_enabled = $this->input->post('ai_enabled', true) == '1' ? '1' : '0';
        if ($token === '') { $this->session->set_flashdata('error','Bot token required'); redirect('telegram_bot'); return; }
        $me = $this->telegram_api->get_me($token);
        if (empty($me['ok'])) { $this->session->set_flashdata('error','Invalid bot token'); redirect('telegram_bot'); return; }
        $username = $me['result']['username'] ?? '';
        $secret = bin2hex(random_bytes(16));
        $this->load->helper('secret');
        $this->basic->insert_data('telegram_accounts', array(
            'user_id'=>$this->uid, 'bot_username'=>$username, 'bot_token'=>secret_encrypt($token),
            'webhook_secret'=>$secret, 'ai_enabled'=>$ai_enabled, 'status'=>'1', 'created_at'=>date('Y-m-d H:i:s'),
        ));
        $acc_id = $this->db->insert_id();
        $this->telegram_api->set_webhook($token, base_url('telegram_bot/webhook/'.$acc_id), $secret);
        $this->session->set_flashdata('success','Telegram bot connected: @'.$username);
        redirect('telegram_bot');
    }

    public function delete($id=0)
    {
        $this->db->where(['id'=>(int)$id, 'user_id'=>$this->uid])->delete('telegram_accounts');
        redirect('telegram_bot');
    }

    public function webhook($account_id=0)
    {
        $this->output->set_content_type('application/json');
        try {
            $acc = $this->basic->get_data('telegram_accounts', ['where'=>['id'=>(int)$account_id, 'status'=>'1']]);
            if (empty($acc)) { echo json_encode(['ok'=>false]); return; }
            $acc = $acc[0];
            // verify secret header
            $hdr = isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '';
            if (!hash_equals($acc['webhook_secret'], $hdr)) { echo json_encode(['ok'=>false]); return; }

            $update = json_decode(file_get_contents('php://input'), true);
            $msg = $update['message'] ?? ($update['edited_message'] ?? null);
            if (!$msg || !isset($msg['chat']['id'])) { echo json_encode(['ok'=>true]); return; }
            $chat_id = (string) $msg['chat']['id'];
            $text = isset($msg['text']) ? $msg['text'] : '';
            $name = trim(($msg['from']['first_name'] ?? '').' '.($msg['from']['last_name'] ?? ''));

            $this->load->helper('secret');
            $token = secret_decrypt($acc['bot_token']);

            // log inbound
            $this->log_msg($acc['user_id'], $chat_id, $account_id, 'user', $text !== '' ? $text : '[non-text message]');

            // bot pause check (SPEC-09) — telegram subscribers keyed by chat id in messenger_bot_subscriber if present
            $paused = $this->basic->get_data('messenger_bot_subscriber', ['where'=>['subscribe_id'=>$chat_id,'social_media'=>'tg']], ['bot_paused_until'], '', 1);
            $is_paused = isset($paused[0]['bot_paused_until']) && $paused[0]['bot_paused_until'] !== null && $paused[0]['bot_paused_until'] > date('Y-m-d H:i:s');

            if ($acc['ai_enabled'] == '1' && $text !== '' && !$is_paused) {
                $reply = $this->get_ai_reply_open_ai('', $text, $acc['user_id'], $account_id, $chat_id, 'tg');
                $reply_text = $reply['choices'][0]['text'] ?? '';
                if ($reply_text !== '') {
                    $this->telegram_api->send_message($token, $chat_id, $reply_text);
                    $this->log_msg($acc['user_id'], $chat_id, $account_id, 'bot', $reply_text);
                }
            }
            echo json_encode(['ok'=>true]);
        } catch (Exception $e) {
            log_message('error', 'telegram webhook: '.$e->getMessage());
            echo json_encode(['ok'=>true]);
        }
    }

    private function log_msg($user_id, $chat_id, $account_id, $sender, $content)
    {
        $this->basic->insert_data('livechat_messages', array(
            'user_id'=>$user_id, 'subscriber_id'=>$chat_id, 'page_table_id'=>$account_id, 'fb_page_id'=>'',
            'sender'=>$sender, 'platform'=>'tg', 'agent_name'=>($sender=='bot'?'Bot':''),
            'message_content'=>$content, 'conversation_time'=>date('Y-m-d H:i:s'),
            'fb_message_id'=>'', 'message_status'=>'sent', 'from_business_suite'=>'0',
        ));
    }
}
