<?php
require_once("Home.php"); // loading home controller

/**
 * General audit log (brief §40) — read-only.
 *
 * index()  tenant view: own rows only (audit_log_list() always filters user_id = $this->uid).
 * admin()  admin view: every tenant's rows (Admin session only — checked in __construct()).
 *
 * Both accept the same GET filters: entity_type, action, date_from (Y-m-d), date_to (Y-m-d).
 */
class Audit_log extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper('audit');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    private function filters_from_get()
    {
        return array(
            'entity_type' => (string) $this->input->get('entity_type', true),
            'action'      => (string) $this->input->get('action', true),
            'date_from'   => (string) $this->input->get('date_from', true),
            'date_to'     => (string) $this->input->get('date_to', true),
        );
    }

    /** Tenant: own rows only. */
    public function index()
    {
        $filters = $this->filters_from_get();
        $data['rows'] = $this->db->table_exists('audit_log') ? audit_log_list($this->db, $this->uid, $filters, 200) : array();
        $data['filters'] = $filters;
        $data['is_admin_view'] = false;
        $data['page_title'] = 'Audit Log';
        $data['body'] = 'admin/audit_log/index';
        $this->_viewcontroller($data);
    }

    /** Admin: every tenant's rows. */
    public function admin()
    {
        if ($this->session->userdata('user_type') != 'Admin') redirect('home/access_forbidden', 'location');
        $filters = $this->filters_from_get();
        $data['rows'] = $this->db->table_exists('audit_log') ? audit_log_list($this->db, null, $filters, 200) : array();
        $data['filters'] = $filters;
        $data['is_admin_view'] = true;
        $data['page_title'] = 'Audit Log (all tenants)';
        $data['body'] = 'admin/audit_log/index';
        $this->_viewcontroller($data);
    }
}
