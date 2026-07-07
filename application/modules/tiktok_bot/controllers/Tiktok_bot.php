<?php
/*
Addon Name: TikTok Bot
Unique Name: tiktok_bot
Modules:
{
   "343":{
      "bulk_limit_enabled":"0",
      "limit_enabled":"0",
      "extra_text":"",
      "module_name":"Bot - TikTok Bot"
   }
}
Project ID: 121
Addon URI: https://bot.cremonkey.com
Author: Creative Monkey
Author URI: https://cremonkey.com
Version: 1.0
Description: Auto-reply to TikTok comments and direct messages with AI.
*/

require_once("application/controllers/Home.php");

class Tiktok_bot extends Home
{
    public $addon_data = array();
    public $user_id;
    public $tables = array(
        'tiktok_accounts',
        'tiktok_campaigns',
        'tiktok_reply_reports'
    );

    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) {
            redirect('home/login', 'location');
        }
        $this->member_validity();
        $addon_path = APPPATH . "modules/" . strtolower($this->router->fetch_class()) . "/controllers/" . ucfirst($this->router->fetch_class()) . ".php";
        $this->addon_data = $this->get_addon_data($addon_path);
        $this->user_id = $this->session->userdata('user_id');
        $this->load->config('tiktok');
        $this->load->library('Tiktok_api');
        $this->load->helper(array('form', 'url'));
        $this->lang->load('tiktok_bot', $this->language);
    }

    /**
     * Redirect to module main page.
     */
    public function index()
    {
        redirect('tiktok_bot/accounts', 'location');
    }

    /**
     * List connected TikTok accounts.
     */
    public function accounts()
    {
        $data['page_title'] = $this->lang->line('TikTok Accounts');
        $data['body'] = 'tiktok_bot/account_list';

        $accounts = $this->basic->get_data('tiktok_accounts', array('where' => array('user_id' => $this->user_id)));
        foreach ($accounts as $key => $acc) {
            $accounts[$key]['status'] = (!empty($acc['expires_at']) && strtotime($acc['expires_at']) > time()) ? 'active' : 'expired';
        }
        $data['accounts'] = $accounts;

        $this->_viewcontroller($data);
    }

    /**
     * Start TikTok OAuth login.
     */
    public function connect()
    {
        $redirect_uri = base_url('tiktok_bot/callback');
        $state = md5(uniqid(rand(), true));
        $this->session->set_userdata('tiktok_oauth_state', $state);
        $url = $this->tiktok_api->get_authorize_url($redirect_uri, $state);
        redirect($url, 'location');
    }

    /**
     * Reauthorize an existing account.
     */
    public function reauthorize($account_id = 0)
    {
        $account_id = (int)$account_id;
        $this->session->set_userdata('tiktok_reauthorize_id', $account_id);
        $this->connect();
    }

    /**
     * OAuth callback handler.
     */
    public function callback()
    {
        // display mode redirects back with ?code=, business mode with ?auth_code=
        $code = $this->input->get('code') ?: $this->input->get('auth_code');
        $state = $this->input->get('state');
        $saved_state = $this->session->userdata('tiktok_oauth_state');
        $this->session->unset_userdata('tiktok_oauth_state');

        if (empty($code)) {
            $this->session->set_flashdata('error_message', $this->lang->line('Authorization code missing'));
            redirect('tiktok_bot/accounts', 'location');
            return;
        }
        if (empty($saved_state) || $state !== $saved_state) {
            $this->session->set_flashdata('error_message', $this->lang->line('TikTok authorization failed'));
            redirect('tiktok_bot/accounts', 'location');
            return;
        }

        $redirect_uri = base_url('tiktok_bot/callback');
        $token_result = $this->tiktok_api->get_access_token($code, $redirect_uri);
        if ($token_result['status'] != '1') {
            $this->session->set_flashdata('error_message', $token_result['message']);
            redirect('tiktok_bot/accounts', 'location');
            return;
        }

        $token_data = $token_result['data'];
        $access_token = isset($token_data['access_token']) ? $token_data['access_token'] : '';
        $refresh_token = isset($token_data['refresh_token']) ? $token_data['refresh_token'] : '';
        $open_id = isset($token_data['open_id']) ? $token_data['open_id'] : '';
        $expires_in = isset($token_data['expires_in']) ? (int)$token_data['expires_in'] : 0;

        if (empty($access_token) || empty($open_id)) {
            $this->session->set_flashdata('error_message', $this->lang->line('TikTok authorization failed'));
            redirect('tiktok_bot/accounts', 'location');
            return;
        }

        $user_result = $this->tiktok_api->get_user_info($access_token, $open_id);
        if ($user_result['status'] != '1') {
            $this->session->set_flashdata('error_message', $this->lang->line('Failed to fetch TikTok account info'));
            redirect('tiktok_bot/accounts', 'location');
            return;
        }

        $user_info = $user_result['data']['user'];
        $display_name = isset($user_info['display_name']) ? $user_info['display_name'] : '';
        $profile_picture = isset($user_info['avatar_url']) ? $user_info['avatar_url'] : '';
        $union_id = isset($user_info['union_id']) ? $user_info['union_id'] : '';
        $bio = isset($user_info['bio_description']) ? $user_info['bio_description'] : '';
        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);

        $account_id = (int)$this->session->userdata('tiktok_reauthorize_id');
        $this->session->unset_userdata('tiktok_reauthorize_id');

        $insert_data = array(
            'user_id'         => $this->user_id,
            'open_id'         => $open_id,
            'union_id'        => $union_id,
            'display_name'    => $display_name,
            'profile_picture' => $profile_picture,
            'bio'             => $bio,
            'access_token'    => $access_token,
            'refresh_token'   => $refresh_token,
            'expires_at'      => $expires_at,
            'updated_at'      => date('Y-m-d H:i:s')
        );

        $existing = $this->basic->get_data('tiktok_accounts', array('where' => array('user_id' => $this->user_id, 'open_id' => $open_id)), array('id'));
        if (!empty($existing)) {
            $account_id = $existing[0]['id'];
            $this->basic->update_data('tiktok_accounts', array('id' => $account_id), $insert_data);
        } else {
            $insert_data['created_at'] = date('Y-m-d H:i:s');
            $this->basic->insert_data('tiktok_accounts', $insert_data);
        }

        $this->session->set_flashdata('success_message', $this->lang->line('Account saved successfully'));
        redirect('tiktok_bot/accounts', 'location');
    }

    /**
     * Delete a connected account and its campaigns/reports.
     */
    public function delete_account_action($account_id = 0)
    {
        $account_id = (int)$account_id;
        if ($account_id <= 0) {
            $this->session->set_flashdata('error_message', 'Invalid account');
            redirect('tiktok_bot/accounts', 'location');
            return;
        }
        $this->basic->delete_data('tiktok_accounts', array('id' => $account_id, 'user_id' => $this->user_id));
        $campaigns = $this->basic->get_data('tiktok_campaigns', array('where' => array('account_id' => $account_id, 'user_id' => $this->user_id)), array('id'));
        foreach ($campaigns as $campaign) {
            $this->basic->delete_data('tiktok_reply_reports', array('campaign_id' => $campaign['id']));
            $this->basic->delete_data('tiktok_campaigns', array('id' => $campaign['id']));
        }
        $this->session->set_flashdata('success_message', $this->lang->line('Account deleted successfully'));
        redirect('tiktok_bot/accounts', 'location');
    }

    /**
     * Campaigns dashboard.
     */
    public function campaigns()
    {
        $data['page_title'] = $this->lang->line('Auto-Reply Campaigns');
        $data['body'] = 'tiktok_bot/index';
        $data['accounts'] = $this->basic->get_data('tiktok_accounts', array('where' => array('user_id' => $this->user_id, 'expires_at >' => date('Y-m-d H:i:s'))));
        $this->_viewcontroller($data);
    }

    /**
     * DataTables AJAX for campaigns.
     */
    public function campaign_data()
    {
        $this->ajax_check();
        $search_value = $this->input->post('search')['value'];
        $display_columns = array('#', 'id', 'account_name', 'campaign_type', 'reply_type', 'status', 'created_at', 'actions');
        $search_columns = array('tiktok_accounts.display_name', 'tiktok_campaigns.campaign_type', 'tiktok_campaigns.reply_type', 'tiktok_campaigns.status');

        $page = isset($_POST['start']) ? (int)$_POST['start'] : 0;
        $rows = isset($_POST['length']) ? (int)$_POST['length'] : 10;
        $page = $page / $rows;

        $where = array('where' => array('tiktok_campaigns.user_id' => $this->user_id));
        $join = array('tiktok_accounts' => 'tiktok_accounts.id=tiktok_campaigns.account_id,left');

        if (!empty($search_value)) {
            $where['or_where'] = array();
            foreach ($search_columns as $col) {
                $where['or_like'][$col] = $search_value;
            }
        }

        $info = $this->basic->get_data('tiktok_campaigns', $where, array('tiktok_campaigns.*', 'tiktok_accounts.display_name as account_name'), $join, $rows, $page, 'tiktok_campaigns.id desc');
        $total_rows = $this->basic->count_row('tiktok_campaigns', $where, 'tiktok_campaigns.id', $join);

        $data = array();
        foreach ($info as $row) {
            $data[] = array(
                'id'               => $row['id'],
                'account_name'     => $row['account_name'],
                'campaign_type'    => ucfirst($row['campaign_type']),
                'reply_type'       => ucfirst($row['reply_type']),
                'status'           => $row['status'] == 'active' ? '<span class="badge badge-success">' . $this->lang->line('Active') . '</span>' : '<span class="badge badge-danger">' . $this->lang->line('Inactive') . '</span>',
                'created_at'       => $row['created_at'],
                'auto_reply_text'  => $row['auto_reply_text'],
                'ai_training_data' => $row['ai_training_data'],
                'account_id'       => $row['account_id']
            );
        }

        echo json_encode(array('draw' => (int)$_POST['draw'], 'recordsTotal' => $total_rows, 'recordsFiltered' => $total_rows, 'data' => $data));
    }

    /**
     * Save campaign (create or update).
     */
    public function save_campaign_action()
    {
        $this->ajax_check();
        $id = (int)$this->input->post('id', true);
        $account_id = (int)$this->input->post('account_id', true);
        $campaign_type = $this->input->post('campaign_type', true);
        $reply_type = $this->input->post('reply_type', true);
        $status = $this->input->post('status', true) == 'active' ? 'active' : 'inactive';
        $auto_reply_text = $this->input->post('auto_reply_text', false);
        $ai_training_data = $this->input->post('ai_training_data', false);

        // Validate account ownership
        $account = $this->basic->get_data('tiktok_accounts', array('where' => array('id' => $account_id, 'user_id' => $this->user_id)), array('id'));
        if (empty($account)) {
            echo json_encode(array('status' => '0', 'message' => 'Invalid account'));
            return;
        }

        if (!in_array($campaign_type, array('comment', 'dm')) || !in_array($reply_type, array('text', 'ai'))) {
            echo json_encode(array('status' => '0', 'message' => 'Invalid campaign configuration'));
            return;
        }

        // DM feature flag
        if ($campaign_type == 'dm' && !$this->config->item('tiktok_dm_enabled')) {
            echo json_encode(array('status' => '0', 'message' => $this->lang->line('DM campaigns require TikTok Messaging API access.')));
            return;
        }

        $insert_data = array(
            'user_id'          => $this->user_id,
            'account_id'       => $account_id,
            'campaign_type'    => $campaign_type,
            'reply_type'       => $reply_type,
            'status'           => $status,
            'auto_reply_text'  => $auto_reply_text,
            'ai_training_data' => $ai_training_data,
            'updated_at'       => date('Y-m-d H:i:s')
        );

        if ($id > 0) {
            $existing = $this->basic->get_data('tiktok_campaigns', array('where' => array('id' => $id, 'user_id' => $this->user_id)), array('id'));
            if (empty($existing)) {
                echo json_encode(array('status' => '0', 'message' => 'Campaign not found'));
                return;
            }
            $this->basic->update_data('tiktok_campaigns', array('id' => $id), $insert_data);
        } else {
            $insert_data['created_at'] = date('Y-m-d H:i:s');
            $this->basic->insert_data('tiktok_campaigns', $insert_data);
        }

        echo json_encode(array('status' => '1', 'message' => $this->lang->line('Campaign saved successfully')));
    }

    /**
     * Delete a campaign and its reports.
     */
    public function delete_campaign_action()
    {
        $this->ajax_check();
        $id = (int)$this->input->post('id', true);
        if ($id <= 0) {
            echo json_encode(array('status' => '0', 'message' => 'Invalid campaign'));
            return;
        }
        $this->basic->delete_data('tiktok_reply_reports', array('campaign_id' => $id));
        $this->basic->delete_data('tiktok_campaigns', array('id' => $id, 'user_id' => $this->user_id));
        echo json_encode(array('status' => '1', 'message' => $this->lang->line('Campaign deleted successfully')));
    }

    /**
     * Reports list view.
     */
    public function reports()
    {
        $data['page_title'] = $this->lang->line('Activity Reports');
        $data['body'] = 'tiktok_bot/report_list';
        $this->_viewcontroller($data);
    }

    /**
     * DataTables AJAX for reports.
     */
    public function report_data()
    {
        $this->ajax_check();
        $search_value = $this->input->post('search')['value'];
        $search_columns = array('tiktok_campaigns.campaign_type', 'tiktok_reply_reports.trigger_text', 'tiktok_reply_reports.reply_text', 'tiktok_reply_reports.status');

        $page = isset($_POST['start']) ? (int)$_POST['start'] : 0;
        $rows = isset($_POST['length']) ? (int)$_POST['length'] : 10;
        $page = $page / $rows;

        $where = array('where' => array('tiktok_reply_reports.user_id' => $this->user_id));
        $join = array('tiktok_campaigns' => 'tiktok_campaigns.id=tiktok_reply_reports.campaign_id,left');

        if (!empty($search_value)) {
            $where['or_like'] = array();
            foreach ($search_columns as $col) {
                $where['or_like'][$col] = $search_value;
            }
        }

        $info = $this->basic->get_data('tiktok_reply_reports', $where, array('tiktok_reply_reports.*', 'tiktok_campaigns.campaign_type'), $join, $rows, $page, 'tiktok_reply_reports.id desc');
        $total_rows = $this->basic->count_row('tiktok_reply_reports', $where, 'tiktok_reply_reports.id', $join);

        $data = array();
        foreach ($info as $row) {
            $data[] = array(
                'id'            => $row['id'],
                'campaign_name' => ucfirst($row['campaign_type']) . ' #' . $row['campaign_id'],
                'trigger_text'  => $row['trigger_text'],
                'reply_text'    => $row['reply_text'],
                'status'        => $row['status'] == 'success' ? '<span class="badge badge-success">' . $this->lang->line('Success') . '</span>' : '<span class="badge badge-danger">' . $this->lang->line('Failed') . '</span>',
                'created_at'    => $row['created_at']
            );
        }

        echo json_encode(array('draw' => (int)$_POST['draw'], 'recordsTotal' => $total_rows, 'recordsFiltered' => $total_rows, 'data' => $data));
    }

    /**
     * Add-on activation: register module, create sidebar, and create tables.
     */
    public function activate()
    {
        $this->ajax_check();

        $addon_controller_name = ucfirst($this->router->fetch_class());
        $purchase_code = $this->input->post('purchase_code');
        $this->addon_credential_check($purchase_code, strtolower($addon_controller_name));

        $sidebar = array(
            0 => array(
                'name'          => 'TikTok Bot',
                'url'           => 'tiktok_bot/accounts',
                'icon'          => 'fab fa-tiktok',
                'module_access' => '343',
                'child_info'    => array('have_child' => '0')
            )
        );

        $this->_create_tables();
        $this->register_addon($addon_controller_name, $sidebar, array(), $purchase_code);
    }

    /**
     * Add-on deactivation: unregister module and sidebar.
     */
    public function deactivate()
    {
        $this->ajax_check();
        $addon_controller_name = ucfirst($this->router->fetch_class());
        $this->unregister_addon($addon_controller_name);
    }

    /**
     * Add-on deletion: unregister, drop tables, and remove files.
     */
    public function delete()
    {
        $this->ajax_check();
        $addon_controller_name = ucfirst($this->router->fetch_class());

        $sql = array(
            1 => "DROP TABLE IF EXISTS `tiktok_accounts`;",
            2 => "DROP TABLE IF EXISTS `tiktok_campaigns`;",
            3 => "DROP TABLE IF EXISTS `tiktok_reply_reports`;"
        );

        $this->delete_addon($addon_controller_name, $sql);
    }

    /**
     * Create module tables.
     */
    protected function _create_tables()
    {
        $this->load->dbforge();

        if (!$this->db->table_exists('tiktok_accounts')) {
            $this->dbforge->add_field(array(
                'id'              => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE),
                'user_id'         => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE),
                'open_id'         => array('type' => 'VARCHAR', 'constraint' => 255),
                'union_id'        => array('type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE),
                'display_name'    => array('type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE),
                'profile_picture' => array('type' => 'VARCHAR', 'constraint' => 500, 'null' => TRUE),
                'bio'             => array('type' => 'TEXT', 'null' => TRUE),
                'access_token'    => array('type' => 'TEXT'),
                'refresh_token'   => array('type' => 'TEXT', 'null' => TRUE),
                'expires_at'      => array('type' => 'DATETIME', 'null' => TRUE),
                'created_at'      => array('type' => 'DATETIME'),
                'updated_at'      => array('type' => 'DATETIME')
            ));
            $this->dbforge->add_key('id', TRUE);
            $this->dbforge->create_table('tiktok_accounts', TRUE);
            $this->db->query('ALTER TABLE `tiktok_accounts` ADD INDEX `user_id_open_id` (`user_id`, `open_id`)');
        }

        if (!$this->db->table_exists('tiktok_campaigns')) {
            $this->dbforge->add_field(array(
                'id'               => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE),
                'user_id'          => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE),
                'account_id'       => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE),
                'campaign_type'    => array('type' => "ENUM('comment','dm')", 'default' => 'comment'),
                'reply_type'       => array('type' => "ENUM('text','ai')", 'default' => 'text'),
                'auto_reply_text'  => array('type' => 'TEXT', 'null' => TRUE),
                'ai_training_data' => array('type' => 'TEXT', 'null' => TRUE),
                'status'           => array('type' => "ENUM('active','inactive')", 'default' => 'active'),
                'created_at'       => array('type' => 'DATETIME'),
                'updated_at'       => array('type' => 'DATETIME')
            ));
            $this->dbforge->add_key('id', TRUE);
            $this->dbforge->create_table('tiktok_campaigns', TRUE);
            $this->db->query('ALTER TABLE `tiktok_campaigns` ADD INDEX `account_status` (`account_id`, `status`)');
        }

        if (!$this->db->table_exists('tiktok_reply_reports')) {
            $this->dbforge->add_field(array(
                'id'           => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE),
                'user_id'      => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE),
                'campaign_id'  => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE),
                'account_id'   => array('type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE),
                'comment_id'   => array('type' => 'VARCHAR', 'constraint' => 128, 'null' => TRUE),
                'trigger_text' => array('type' => 'TEXT', 'null' => TRUE),
                'reply_text'   => array('type' => 'TEXT', 'null' => TRUE),
                'api_response' => array('type' => 'TEXT', 'null' => TRUE),
                'status'       => array('type' => "ENUM('success','failed')", 'default' => 'failed'),
                'created_at'   => array('type' => 'DATETIME')
            ));
            $this->dbforge->add_key('id', TRUE);
            $this->dbforge->create_table('tiktok_reply_reports', TRUE);
        }
    }
}
