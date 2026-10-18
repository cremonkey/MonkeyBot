<?php
require_once("application/controllers/Home.php");

/**
 * Connect Instagram professional accounts with Instagram Login (no Facebook Page needed).
 * Logic lives in application/helpers/ig_login_helper.php; this controller is the web glue:
 *   ig_login/settings  (Admin)  Instagram app id + secret, shows the URLs to paste into Meta
 *   ig_login/connect            starts OAuth (state in session)
 *   ig_login/callback           finishes OAuth, stores the account, subscribes webhooks
 */
class Ig_login extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) { redirect('home/login', 'location'); exit; }
        $this->member_validity();
        $this->user_id = $this->session->userdata('user_id');
        $this->load->helper(array('secret', 'fb_graph', 'ig_login'));
    }

    private function redirect_uri() { return base_url('ig_login/callback'); }

    private function back($msg, $ok = false)
    {
        $this->session->set_flashdata($ok ? 'success_message' : 'error_message', $msg);
        $this->session->set_userdata($ok ? 'ig_login_ok' : 'limit_cross', $msg);
        redirect('instagram_reply/get_account_lists', 'location');
        exit;
    }

    public function settings()
    {
        if ($this->session->userdata('user_type') != 'Admin') { redirect('home/access_forbidden', 'location'); exit; }
        if (!$this->db->table_exists('ig_login_config')) show_error('Run php index.php cron_hub migrate first.');

        $row = $this->db->query('SELECT * FROM ig_login_config ORDER BY id ASC LIMIT 1')->row_array();
        $saved = '';
        if ($this->input->method() === 'post') {
            $app_id = preg_replace('/[^0-9]/', '', (string) $this->input->post('app_id', true));
            $secret = trim((string) $this->input->post('app_secret'));
            $data = array('app_id' => $app_id, 'enabled' => $this->input->post('enabled') ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'));
            if ($secret !== '') $data['app_secret_enc'] = secret_encrypt($secret);   // blank = keep the stored one
            if (empty($row)) $this->db->insert('ig_login_config', $data);
            else $this->db->where('id', $row['id'])->update('ig_login_config', $data);
            $row = $this->db->query('SELECT * FROM ig_login_config ORDER BY id ASC LIMIT 1')->row_array();
            $saved = $this->lang->line('Saved');
        }
        $data = array(
            'row' => $row, 'saved' => $saved,
            'redirect_uri' => $this->redirect_uri(),
            'webhook_url' => base_url('home/central_webhook_callback'),
            'scopes' => IGL_SCOPES, 'fields' => IGL_WEBHOOK_FIELDS,
            'body' => 'ig_login/settings', 'page_title' => $this->lang->line('Instagram Login'),
        );
        $this->_viewcontroller($data);
    }

    public function connect()
    {
        $cfg = igl_config($this->db);
        if (!$cfg) $this->back($this->lang->line('Instagram Login is not set up yet. Ask the admin to add the Instagram app.'));
        $state = bin2hex(random_bytes(16));
        $this->session->set_userdata('ig_login_state', $state);
        redirect(igl_authorize_url($cfg['app_id'], $this->redirect_uri(), $state), 'location');
    }

    public function callback()
    {
        $state = (string) $this->input->get('state', true);
        $expected = (string) $this->session->userdata('ig_login_state');
        $this->session->unset_userdata('ig_login_state');
        if ($expected === '' || !hash_equals($expected, $state)) $this->back($this->lang->line('Instagram connection expired, please try again.'));
        if ($this->input->get('error', true)) $this->back($this->lang->line('Instagram connection was cancelled.'));

        $cfg = igl_config($this->db);
        if (!$cfg) $this->back($this->lang->line('Instagram Login is not set up yet.'));
        $code = preg_replace('/#_$/', '', (string) $this->input->get('code'));
        if ($code === '') $this->back($this->lang->line('Instagram did not return a code.'));

        $acc = igl_exchange_code($cfg, $code, $this->redirect_uri());
        if (!$acc['ok']) { log_message('error', 'ig_login callback: ' . $acc['error']); $this->back($this->lang->line('Instagram connection failed') . ': ' . $acc['error']); }

        $exists = $this->db->query("SELECT id FROM facebook_rx_fb_page_info WHERE instagram_business_account_id = ? AND user_id = ? AND deleted = '0' LIMIT 1", array($acc['ig_id'], $this->user_id))->row_array();
        if (empty($exists)) {
            $status = $this->_check_usage($module_id = 65, $request = 1);
            if ($status == "2" || $status == "3") $this->back($this->lang->line("Module limit is over."));
        }

        $res = igl_upsert_account($this->db, $this->user_id, $acc);
        if (isset($res['error'])) $this->back($this->lang->line($res['error']));
        if ($res['new']) {
            $this->_insert_usage_log($module_id = 65, $request = 1);
            $this->load->helper('audit'); audit_record($this->user_id, 'channel.connected', 'ig_login', $res['id']);
        }

        $sub_err = igl_subscribe_webhooks($acc['token']);
        if ($sub_err !== '') {
            log_message('error', 'ig_login subscribe: ' . $sub_err);
            $this->back('@' . $acc['username'] . ' ' . $this->lang->line('connected, but webhook subscription failed') . ': ' . $sub_err);
        }
        $this->back('@' . $acc['username'] . ' ' . $this->lang->line('connected with Instagram Login.'), true);
    }
}
