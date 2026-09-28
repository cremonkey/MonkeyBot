<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'libraries/Api_v2_controller.php';

/**
 * GET /api/v2/crm — Task 28 (C13, brief §37). Read-only, token-scoped deals list/detail over
 * `crm_deals` (application/helpers/crm_helper.php owns writes/pipeline logic; this only reads,
 * scoped by user_id the same way the admin Crm.php controller's queries are). Guarded by
 * table_exists('crm_deals') so a tenant on a build before the CRM migration ran gets an empty
 * list instead of a DB error.
 */
class Crm extends Api_v2_controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_scope('crm');
    }

    /** GET /api/v2/crm/deals — paginated list, optional ?status=&stage_id= filters. */
    public function deals()
    {
        list($page, $per_page, $offset) = $this->pagination_bounds();

        if (!$this->db->table_exists('crm_deals')) {
            $this->ok(array(), api_pagination_meta($page, $per_page, 0));
        }

        $q = $this->db->select('id, pipeline_id, stage_id, title, value, status, source, created_at, updated_at, won_at')
            ->from('crm_deals')
            ->where('user_id', $this->uid);

        $status = $this->input->get('status');
        if ($status !== null && $status !== '') $q->where('status', $status);

        $stage_id = $this->input->get('stage_id');
        if ($stage_id !== null && $stage_id !== '') $q->where('stage_id', (int) $stage_id);

        $total = $q->count_all_results('', false);
        $rows = $q->order_by('updated_at', 'DESC')->limit($per_page, $offset)->get()->result_array();

        $this->ok($rows, api_pagination_meta($page, $per_page, $total));
    }

    /** GET /api/v2/crm/deal/{id} */
    public function deal($id = 0)
    {
        if (!$this->db->table_exists('crm_deals')) $this->fail(404, 'deal not found');

        $deal = $this->db->from('crm_deals')->where('id', (int) $id)->where('user_id', $this->uid)->get()->row_array();
        if (empty($deal)) $this->fail(404, 'deal not found');
        $this->ok($deal);
    }
}
