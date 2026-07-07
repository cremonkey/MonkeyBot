<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Sales Automation settings: silent-lead follow-ups + daily digest.
 * The jobs themselves run from Cron_hub (cron_hub/run every 5 minutes).
 */
class Sales_automation extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    public function index()
    {
        $data['config'] = $this->db->from('sales_automation_settings')->where('user_id', $this->uid)->get()->row_array();
        $data['recent'] = $this->db->from('ai_followups')->where('user_id', $this->uid)
            ->order_by('id', 'DESC')->limit(30)->get()->result_array();
        $data['page_title'] = 'Sales Automation';
        $data['body'] = 'admin/sales_automation/index';
        $this->_viewcontroller($data);
    }

    public function save()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $fields = array(
            'followup_enabled'       => $this->input->post('followup_enabled', true) === '1' ? '1' : '0',
            'followup_delay_minutes' => max(15, (int) $this->input->post('followup_delay_minutes', true)),
            'followup_message_ar'    => trim((string) $this->input->post('followup_message_ar', false)),
            'followup_message_en'    => trim((string) $this->input->post('followup_message_en', false)),
            'digest_enabled'         => $this->input->post('digest_enabled', true) === '1' ? '1' : '0',
            'digest_email'           => trim(strip_tags((string) $this->input->post('digest_email', true))),
            'digest_whatsapp'        => preg_replace('/[^0-9+]/', '', (string) $this->input->post('digest_whatsapp', true)),
            'digest_hour'            => min(23, max(0, (int) $this->input->post('digest_hour', true))),
            'updated_at'             => date('Y-m-d H:i:s'),
        );
        $existing = $this->db->from('sales_automation_settings')->where('user_id', $this->uid)->get()->row_array();
        if ($existing) {
            $this->db->where('id', $existing['id'])->update('sales_automation_settings', $fields);
        } else {
            $fields['user_id'] = $this->uid;
            $fields['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert('sales_automation_settings', $fields);
        }
        echo json_encode(['status' => '1', 'message' => 'Saved. The cron picks up changes on its next run (every 5 minutes).']);
    }

    /** Manual trigger for testing: runs the cron jobs immediately. */
    public function run_now()
    {
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $ch = curl_init(site_url('cron_hub/run'));
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true));
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        echo json_encode(['status' => $raw ? '1' : '0', 'message' => $raw ?: ('cron call failed: ' . $err)]);
    }
}
