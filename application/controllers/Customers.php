<?php
require_once("Home.php");

/**
 * Customer 360 (read side) — Task 16 (C1, brief §16/§13). A read-only aggregation over the
 * conversation core (customers, customer_identities — docs/database/conversation-core.md)
 * plus whatever optional tables exist for this tenant: conversations, crm_deals (today keyed
 * by the legacy messenger_bot_subscriber.id via customers.legacy_subscriber_id, same as
 * ab_appointments), ecommerce_cart (keyed by subscriber_id = customer_identities.external_id),
 * conversation labels and internal_notes. Only merge()/unmerge() (Task 29 / C14, brief §16)
 * write anything, and only on an explicit, CSRF+ajax+tenant-owned request — see
 * application/helpers/customer_helper.php's customer_merge()/customer_unmerge().
 *
 * Every query is tenant-scoped through customer_scope_list()/customer_scope_owner()
 * (application/helpers/customer_helper.php), the same pattern Inbox.php uses. The compact
 * panel Inbox shows in its right rail is Inbox::customer_panel(), which reuses the same
 * helper functions so a passing test proves both call sites.
 */
class Customers extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper(array('entitlement', 'customer', 'conversation'));
        entitlement_require('customers');
        // Tenant guard used by every query in this controller (same convention as Inbox.php).
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        // Who is acting (team member id when logged in as a team member, else the account's own
        // id) — same convention as Inbox.php, used for customer_merge_log.actor_id and the
        // audit trail on merge/unmerge.
        $this->actor_id = (int) $this->session->userdata('user_id');
    }

    // ------------------------------------------------------------------ shell pages

    public function index()
    {
        $data['page_title'] = 'Customers';
        $data['body'] = 'admin/customers/index';
        $this->_viewcontroller($data);
    }

    public function profile($customer_id = 0)
    {
        $customer_id = (int) $customer_id;
        $customer = $this->db->select('id, display_name, phone, email, lead_score, source, merged_into_customer_id, created_at, updated_at')
            ->from('customers');
        customer_scope_owner($this->db, $customer_id, $this->uid);
        $customer = $customer->get()->row_array();

        if (empty($customer)) {
            $data['page_title'] = 'Customer not found';
            $data['body'] = 'admin/customers/not_found';
            $this->_viewcontroller($data);
            return;
        }

        $data['customer'] = $customer;
        $data['profile'] = $this->build_profile($customer);
        $data['page_title'] = customer_display_name($customer);
        $data['body'] = 'admin/customers/profile';
        $this->_viewcontroller($data);
    }

    // ------------------------------------------------------------------ AJAX: list

    /** AJAX: paginated, searched, channel-filtered customer list. Tenant-scoped, one query. */
    public function customers_data()
    {
        $this->ajax_check();
        header('Content-Type: application/json');

        $filters = array(
            'search'  => $this->input->post('search', true),
            'channel' => $this->input->post('channel', true),
        );
        list($start, $length) = customer_pagination_bounds($this->input->post('start'), $this->input->post('length'));

        $this->db->select('c.id, c.display_name, c.phone, c.email, c.lead_score, c.source, c.created_at');
        $this->db->from('customers c');
        customer_scope_list($this->db, $this->uid, $filters);

        $total = $this->db->count_all_results('', false); // keeps WHERE, drops SELECT/ORDER/LIMIT for the count
        $this->db->order_by('c.id', 'DESC');
        $this->db->limit($length, $start);
        $rows = $this->db->get()->result_array();

        $ids = array_column($rows, 'id');
        $identities_by_customer = $this->identities_for_customers($ids);

        foreach ($rows as &$r) {
            $r['name'] = customer_display_name($r);
            $identities = isset($identities_by_customer[$r['id']]) ? $identities_by_customer[$r['id']] : array();
            $r['channels'] = array_keys(customer_identities_by_channel($identities));
        }
        unset($r);

        echo json_encode(array('status' => '1', 'total' => $total, 'rows' => $rows));
    }

    // ------------------------------------------------------------------ shared aggregation

    /**
     * Full 360 payload for the profile page. Inbox::customer_panel() calls the compact
     * subset of the same pieces (see below) rather than duplicating these queries.
     */
    private function build_profile(array $customer)
    {
        $cid = (int) $customer['id'];
        static $ready = array(); // one table_exists() cache per request, shared across the calls below

        $identities = $this->db->select('platform, external_id, channel_account_id, verified, created_at')
            ->from('customer_identities')->where('customer_id', $cid)->where('user_id', $this->uid)
            ->order_by('platform', 'ASC')->get()->result_array();

        $deals = customer_deals_for($this->db, $this->uid, $customer, $ready);

        return array(
            'identities'      => $identities,
            'by_channel'      => customer_identities_by_channel($identities),
            'channel_count'   => customer_channel_count($identities),
            'conversations'   => customer_conversations_for($this->db, $this->uid, $cid, 20),
            'deals'           => $deals,
            'lead_stage'      => customer_lead_stage_summary($deals),
            'appointments'    => customer_appointments_for($this->db, $this->uid, $customer, $ready),
            'orders'          => customer_orders_for($this->db, $this->uid, $identities, $ready),
            'labels'          => customer_labels_for($this->db, $this->uid, $cid, $ready),
            'notes'           => customer_notes_for($this->db, $this->uid, $cid, $ready),
            'ai_summary'      => $this->ai_summaries_for_customer($cid),
            'events'          => $this->recent_events_for_customer($cid),
            // Task 29 (C14, brief §16) — merge UI. All three are safe to compute even for a
            // merged-away customer (merge_suggestions_for() short-circuits in that case below);
            // none of them ever writes anything.
            'merge_suggestions' => $this->merge_suggestions_for($customer),
            'merge_source_log'  => customer_active_merge_as_source($this->db, $this->uid, $cid), // "Merged into #X" banner + Undo, on the retired customer's own profile
            'recent_merges_in'  => customer_recent_merges_into($this->db, $this->uid, $cid),      // Undo list, on the surviving (keep) customer's profile
        );
    }

    /**
     * Merge suggestions that involve this specific customer (Task 29 / C14, brief §16) — the
     * profile page's "Merge" button. Computed directly for this one customer (review round 1:
     * not tenant-wide-then-filtered). Only ever suggests, never merges anything. A customer
     * already merged away is not offered further suggestions — merging it again is meaningless.
     */
    private function merge_suggestions_for(array $customer)
    {
        if (!empty($customer['merged_into_customer_id'])) return array();
        return customer_merge_suggestions_for_customer($this->db, $this->uid, (int) $customer['id'], 20);
    }

    /** conversations.last_summary — AI summary per conversation (already stored, no model
     *  called here). NULL/[] when the column doesn't exist yet. */
    private function ai_summaries_for_customer($customer_id)
    {
        static $has = null;
        if ($has === null) $has = $this->db->table_exists('conversations') && $this->db->field_exists('last_summary', 'conversations');
        if (!$has) return array();
        return $this->db->select('id AS conversation_id, platform, last_summary')
            ->from('conversations')->where('customer_id', (int) $customer_id)->where('user_id', $this->uid)
            ->where('last_summary IS NOT NULL')->order_by('last_message_at', 'DESC')->limit(5)
            ->get()->result_array();
    }

    /** conversation_events for every conversation of this customer — recent activity feed. */
    private function recent_events_for_customer($customer_id)
    {
        static $ready = array();
        if (!customer_optional_table_ready($this->db, 'conversation_events', $ready)) return array();
        return $this->db->select('e.id, e.conversation_id, e.event_type, e.created_at')
            ->from('conversation_events e')
            ->join('conversations c', 'c.id = e.conversation_id')
            ->where('e.user_id', $this->uid)->where('c.customer_id', (int) $customer_id)
            ->order_by('e.id', 'DESC')->limit(20)->get()->result_array();
    }

    /** customer_id => [identity rows], for the list page's channel badges. Tenant-scoped. */
    private function identities_for_customers(array $customer_ids)
    {
        if (empty($customer_ids)) return array();
        $rows = $this->db->select('customer_id, platform, external_id, channel_account_id')
            ->from('customer_identities')->where('user_id', $this->uid)->where_in('customer_id', $customer_ids)
            ->get()->result_array();
        $by_customer = array();
        foreach ($rows as $r) $by_customer[$r['customer_id']][] = $r;
        return $by_customer;
    }

    // ------------------------------------------------------------------ merge (Task 29 / C14, brief §16)
    //
    // REVIEW GATE: these three endpoints are the only way a merge ever happens — nothing in
    // this codebase merges customers automatically. suggestions() only returns candidates for
    // the owner to look at; merge() requires an explicit call with both ids; unmerge() reverses
    // exactly one merge by its log id. All three: ajax_check() + csrf_token_check() + every id
    // received re-checked against $this->uid inside customer_helper.php (never trust the ids
    // alone — same pattern as owns_deal()/customer_scope_owner() elsewhere in this file).

    /** AJAX POST: merge $merge_id into $keep_id. Both must belong to this tenant. */
    public function merge()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $keep_id = (int) $this->input->post('keep_id');
        $merge_id = (int) $this->input->post('merge_id');

        list($ok, $errors, $result) = customer_merge($this->db, $keep_id, $merge_id, $this->actor_id, $this->uid);
        if (!$ok) {
            echo json_encode(array('status' => '0', 'message' => implode(' ', $errors), 'errors' => $errors));
            return;
        }
        echo json_encode(array('status' => '1', 'message' => 'Merged.', 'result' => $result));
    }

    /** AJAX POST: undo one merge, by customer_merge_log.id. Must belong to this tenant. */
    public function unmerge()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $log_id = (int) $this->input->post('log_id');

        list($ok, $errors, $result) = customer_unmerge($this->db, $log_id, $this->actor_id, $this->uid);
        if (!$ok) {
            // $result is still included on a partial restore (review round 1: honest reporting
            // of what did and didn't come back, not just a bare failure) — null when the
            // refusal happened before anything was attempted (not found, cross-tenant, etc).
            echo json_encode(array('status' => '0', 'message' => implode(' ', $errors), 'errors' => $errors, 'result' => $result));
            return;
        }
        echo json_encode(array('status' => '1', 'message' => 'Merge undone.', 'result' => $result));
    }

    /** AJAX: this tenant's merge suggestions (same verified phone/email on two customers). */
    public function suggestions()
    {
        $this->ajax_check();
        header('Content-Type: application/json');
        echo json_encode(array('status' => '1', 'rows' => customer_merge_suggestions($this->db, $this->uid, 50)));
    }
}
