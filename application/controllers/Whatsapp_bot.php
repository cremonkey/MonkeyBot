<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * SPEC-10 — WhatsApp Cloud API channel.
 * webhook() is PUBLIC (GET verify + POST messages); admin methods require login.
 * WhatsApp subscribers reuse messenger_bot_subscriber conventions loosely: conversations are
 * logged to livechat_messages (platform='wa') and AI memory keys on (page_id=account_id, subscribe_id=wa_id, 'wa').
 */
class Whatsapp_bot extends Home
{
    public function __construct()
    {
        parent::__construct();
        $seg = $this->uri->segment(2);
        if ($seg !== 'webhook') {
            if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
            $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        }
        $this->load->library('Whatsapp_api');
        $this->load->helper('secret');
    }

    public function index()
    {
        $data['accounts'] = $this->basic->get_data('whatsapp_accounts', ['where'=>['user_id'=>$this->uid]], '', '', '', '', 'id DESC');
        $data['webhook_base'] = base_url('whatsapp_bot/webhook/');
        $data['page_title'] = 'WhatsApp Bot';
        $data['body'] = 'admin/whatsapp_bot/index';
        $this->_viewcontroller($data);
    }

    public function save()
    {
        $this->csrf_token_check();
        $label = strip_tags((string)$this->input->post('label', true));
        $waba_id = strip_tags((string)$this->input->post('waba_id', true));
        $pnid = strip_tags((string)$this->input->post('phone_number_id', true));
        $display = strip_tags((string)$this->input->post('display_phone', true));
        $token = trim((string)$this->input->post('access_token', true));
        $app_secret = trim((string)$this->input->post('app_secret', true));
        $ai_enabled = $this->input->post('ai_enabled', true) == '1' ? '1' : '0';
        if ($pnid === '' || $token === '') { $this->session->set_flashdata('error','Phone number ID and access token are required'); redirect('whatsapp_bot'); return; }
        $verify = bin2hex(random_bytes(16));
        $this->basic->insert_data('whatsapp_accounts', array(
            'user_id'=>$this->uid, 'label'=>$label, 'waba_id'=>$waba_id, 'phone_number_id'=>$pnid,
            'display_phone'=>$display, 'access_token'=>secret_encrypt($token),
            'app_secret'=>($app_secret !== '' ? secret_encrypt($app_secret) : null), 'verify_token'=>$verify,
            'ai_enabled'=>$ai_enabled, 'status'=>'1', 'created_at'=>date('Y-m-d H:i:s'),
        ));
        $this->session->set_flashdata('success','WhatsApp number connected. Configure the webhook in Meta with the callback URL and verify token shown.');
        redirect('whatsapp_bot');
    }

    public function delete($id=0)
    {
        if (!hash_equals((string)$this->session->userdata('csrf_token_session'), (string)$this->input->get('t'))) show_error('Invalid token', 403);
        $this->db->where(['id'=>(int)$id, 'user_id'=>$this->uid])->delete('whatsapp_accounts');
        redirect('whatsapp_bot');
    }

    public function webhook($account_id=0)
    {
        $acc = $this->basic->get_data('whatsapp_accounts', ['where'=>['id'=>(int)$account_id, 'status'=>'1']]);
        // GET: Meta verification handshake
        if ($this->input->method() === 'get') {
            $mode = $this->input->get('hub_mode') ?: $this->input->get('hub.mode');
            $vt = $this->input->get('hub_verify_token') ?: $this->input->get('hub.verify_token');
            $challenge = $this->input->get('hub_challenge') ?: $this->input->get('hub.challenge');
            if ($mode === 'subscribe' && !empty($acc) && hash_equals($acc[0]['verify_token'], (string)$vt)) {
                echo $challenge; return;
            }
            $this->output->set_status_header(403); echo 'forbidden'; return;
        }
        // POST: inbound messages
        $this->output->set_content_type('application/json');
        try {
            if (empty($acc)) { echo json_encode(['ok'=>false]); return; }
            $acc = $acc[0];
            $raw_body = file_get_contents('php://input');
            // review I1: verify Meta signature when an app secret is configured
            $app_secret = isset($acc['app_secret']) && $acc['app_secret'] !== null ? secret_decrypt($acc['app_secret']) : '';
            if ($app_secret !== '') {
                $sig = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
                $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $app_secret);
                if (!hash_equals($expected, (string)$sig)) { $this->output->set_status_header(403); echo json_encode(['ok'=>false]); return; }
            }
            $token = secret_decrypt($acc['access_token']);
            $body = json_decode($raw_body, true);
            $entries = $body['entry'] ?? array();
            foreach ($entries as $entry) {
                foreach (($entry['changes'] ?? array()) as $change) {
                    $value = $change['value'] ?? array();
                    $messages = $value['messages'] ?? array();
                    $contacts = $value['contacts'] ?? array();
                    $name = isset($contacts[0]['profile']['name']) ? $contacts[0]['profile']['name'] : '';
                    foreach ($messages as $m) {
                        $from = $m['from'] ?? '';
                        if ($from === '') continue;
                        $text = isset($m['text']['body']) ? $m['text']['body'] : '';
                        $this->log_msg($acc['user_id'], $from, $account_id, 'user', $text !== '' ? $text : '[non-text message]');

                        $paused = $this->basic->get_data('messenger_bot_subscriber', ['where'=>['subscribe_id'=>$from,'social_media'=>'wa']], ['bot_paused_until'], '', 1);
                        $is_paused = isset($paused[0]['bot_paused_until']) && $paused[0]['bot_paused_until'] !== null && $paused[0]['bot_paused_until'] > date('Y-m-d H:i:s');

                        if ($acc['ai_enabled'] == '1' && $text !== '' && !$is_paused) {
                            $reply = $this->get_ai_reply_open_ai('', $text, $acc['user_id'], $account_id, $from, 'wa');
                            $reply_text = $reply['choices'][0]['text'] ?? '';
                            if ($reply_text !== '') {
                                $this->whatsapp_api->send_text($token, $acc['phone_number_id'], $from, $reply_text);
                                $this->log_msg($acc['user_id'], $from, $account_id, 'bot', $reply_text);
                            }
                        }
                    }
                }
            }
            echo json_encode(['ok'=>true]);
        } catch (Exception $e) {
            log_message('error', 'whatsapp webhook: '.$e->getMessage());
            echo json_encode(['ok'=>true]);
        }
    }

    private function log_msg($user_id, $wa_id, $account_id, $sender, $content)
    {
        $this->basic->insert_data('livechat_messages', array(
            'user_id'=>$user_id, 'subscriber_id'=>$wa_id, 'page_table_id'=>$account_id, 'fb_page_id'=>'',
            'sender'=>$sender, 'platform'=>'wa', 'agent_name'=>($sender=='bot'?'Bot':''),
            'message_content'=>$content, 'conversation_time'=>date('Y-m-d H:i:s'),
            'fb_message_id'=>'', 'message_status'=>'sent', 'from_business_suite'=>'0',
        ));
    }
}
