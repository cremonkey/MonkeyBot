<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'libraries/Api_v2_controller.php';

/**
 * GET /api/v2/conversations — Task 28 (C13, brief §37). Read-only, token-scoped list/detail
 * over the conversation core (application/helpers/conversation_helper.php, same tables Inbox.php
 * and Customers.php read). Tenant scope is the bearer token's user_id (Api_v2_controller), never
 * a session — there is no session here.
 */
class Conversations extends Api_v2_controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('conversation');
        $this->require_scope('conversations');
    }

    /** GET /api/v2/conversations/index — paginated list, optional ?platform=&state= filters. */
    public function index()
    {
        list($page, $per_page, $offset) = $this->pagination_bounds();

        $q = $this->db->select('id, platform, state, channel_account_id, external_thread_id, customer_id, last_message_at, created_at, updated_at')
            ->from('conversations')
            ->where('user_id', $this->uid);

        $platform = $this->input->get('platform');
        if ($platform !== null && $platform !== '') $q->where('platform', $platform);

        $state = $this->input->get('state');
        if ($state !== null && $state !== '') $q->where('state', $state);

        // count_all_results(..., FALSE) does not reset the query builder, so the same $q can
        // still be ordered/limited/get() afterwards for the actual page of rows.
        $total = $q->count_all_results('', false);
        $rows = $q->order_by('updated_at', 'DESC')->limit($per_page, $offset)->get()->result_array();

        $this->ok($rows, api_pagination_meta($page, $per_page, $total));
    }

    /** GET /api/v2/conversations/detail/{id} */
    public function detail($id = 0)
    {
        $conversation = conversation_get((int) $id, $this->uid);
        if (empty($conversation)) $this->fail(404, 'conversation not found');
        $this->ok($conversation);
    }
}
