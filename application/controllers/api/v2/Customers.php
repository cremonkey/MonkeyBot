<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'libraries/Api_v2_controller.php';

/**
 * GET /api/v2/customers — Task 28 (C13, brief §37). Read-only, token-scoped, built entirely on
 * Task 16's application/helpers/customer_helper.php (customer_scope_list / customer_scope_owner
 * / customer_pagination_bounds) — the same functions Customers.php (admin UI) and
 * Inbox::customer_panel() use, so this list/detail behave identically to those.
 */
class Customers extends Api_v2_controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('customer');
        $this->require_scope('customers');
    }

    /** GET /api/v2/customers/index — paginated list, optional ?search=&channel= filters. */
    public function index()
    {
        list($page, $per_page, $offset) = $this->pagination_bounds();
        $filters = array(
            'search'  => (string) $this->input->get('search'),
            'channel' => (string) $this->input->get('channel'),
        );

        $q = $this->db->select('c.id, c.display_name, c.phone, c.email, c.lead_score, c.source, c.created_at, c.updated_at')
            ->from('customers c');
        customer_scope_list($q, $this->uid, $filters);

        $total = $q->count_all_results('', false);
        $rows = $q->order_by('c.updated_at', 'DESC')->limit($per_page, $offset)->get()->result_array();

        $this->ok($rows, api_pagination_meta($page, $per_page, $total));
    }

    /** GET /api/v2/customers/detail/{id} */
    public function detail($id = 0)
    {
        $q = $this->db->select('id, display_name, phone, email, lead_score, source, created_at, updated_at')->from('customers');
        customer_scope_owner($q, (int) $id, $this->uid);
        $customer = $q->get()->row_array();

        if (empty($customer)) $this->fail(404, 'customer not found');
        $this->ok($customer);
    }
}
