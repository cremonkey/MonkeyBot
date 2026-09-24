<?php
require_once("Home.php"); // loading home controller

/**
 * Admin > System health ("Automation Health" in owner language): scheduler runs,
 * webhook-signature counters, and the security switches that are still in log mode.
 * Admin only. Reads JSON state files from application/cache; no schema needed.
 */
class System_health extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        if ($this->session->userdata('user_type') != 'Admin') redirect('home/login_page', 'location');
    }

    public function index()
    {
        $this->load->helper('ops_health');
        $jobs = ops_health_read();

        $wh = json_decode((string) @file_get_contents(APPPATH . 'cache/webhook_security.json'), true);
        if (!is_array($wh)) $wh = array();

        $mode = function ($key) {
            $m = (string) $this->config->item($key);
            return in_array($m, array('off', 'log', 'enforce'), true) ? $m : 'log';
        };

        $data['jobs'] = $jobs;
        // 7.1 / Task 2: every scheduled job that records health, shown together so an admin
        // can see all of them without knowing which controller writes which key.
        $data['job_labels'] = array(
            'cron_hub'             => 'Cron hub (follow-ups, digest, retention, ...)',
            'tiktok_poll_comments' => 'TikTok comment polling',
            'lead_score_decay'     => 'Lead score decay',
            'automation_worker'    => 'Automation worker',
            'sequence_worker'      => 'Sequence worker',
        );
        $data['cron_age'] = ops_job_age_seconds('cron_hub');
        $data['webhooks'] = $wh;
        $data['switches'] = array(
            'Meta webhook signature'   => $mode('meta_webhook_signature_mode'),
            'Internal webhook forward' => $mode('internal_webhook_mode'),
            'HTTP cron trigger'        => ((string) $this->config->item('cron_secret') === '') ? 'closed (CLI only)' : 'open with secret',
            'Custom-module entitlements' => $this->config->item('entitlement_enforce_custom_modules') ? 'enforce' : 'log only',
        );
        $data['page_title'] = 'System Health';
        $data['body'] = 'admin/system_health/index';
        $this->_viewcontroller($data);
    }
}
