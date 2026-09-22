<?php
require_once("Home.php");

/**
 * Client provisioning (Phase 11 / brief §31, §32, §40, §51) — a sibling to Agency.php.
 *
 * WHY A SEPARATE CONTROLLER: Agency.php's own docblock promises "one screen ... nothing
 * here writes data", and every method in it is an aggregate SELECT with no transaction.
 * This action creates a `users` row (and, with a template, an `onboarding_state` row)
 * inside a DB transaction and hands back a one-time password. Splitting it out keeps
 * Agency.php's read-only guarantee true by inspection, and lets this write path be
 * unit-tested (tests/provisioning/run.php) without dragging in Agency's dashboard queries.
 *
 * Admin-only, gated exactly like Agency.php's own constructor. This is an agency action,
 * not a tenant-facing feature — it does NOT go through entitlement_helper.php, matching the
 * brief's instruction not to invent a tenant entitlement key for it.
 */
class Agency_provision extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        if ($this->session->userdata('user_type') != 'Admin') redirect('home/access_forbidden', 'location');
        $this->load->helper(array('password', 'industry_template', 'provisioning'));
    }

    /** No page of its own — this controller only answers the AJAX action below. */
    public function index()
    {
        redirect('agency', 'location');
    }

    /**
     * AJAX POST — create a client account end-to-end. All validation, the password/expiry
     * conventions, and the transaction live in provisioning_helper.php so this controller
     * stays a thin HTTP adapter: read input, call the helper, log the audit line, respond.
     */
    public function provision_client()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $input = array(
            'business_name' => (string) $this->input->post('business_name', true),
            'email'         => trim((string) $this->input->post('email', true)),
            'package_id'    => (int) $this->input->post('package_id', true),
            'template_key'  => (string) $this->input->post('template_key', true),
        );

        $admin_id = (int) $this->session->userdata('user_id');
        list($ok, $errors, $result) = provisioning_create_client($this->db, $admin_id, $input);

        if (!$ok) {
            echo json_encode(array(
                'status'  => '0',
                'message' => !empty($errors) ? $errors[0] : 'Could not create the client account.',
                'errors'  => $errors,
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        // Audit trail (brief §40): who, what, when (log timestamp), which tenant. Never the
        // password — it is returned to the browser below and nowhere else.
        log_message('error', 'audit: client ' . $result['user_id'] . ' (' . $result['email'] . ') provisioned by admin '
            . $admin_id . ' [package ' . $result['package_id'] . ($result['template_key'] ? ', template ' . $result['template_key'] : ', no starter template') . ']');

        echo json_encode(array(
            'status'  => '1',
            'message' => 'Client account created.',
            'client'  => array(
                'id'           => $result['user_id'],
                'name'         => $result['name'],
                'email'        => $result['email'],
                'package_name' => $result['package_name'],
                'expired_date' => $result['expired_date'],
                'template_key' => $result['template_key'],
            ),
            // Shown ONCE in the UI as "copy this now" — never logged, never re-fetchable.
            'temp_password' => $result['temp_password'],
        ), JSON_UNESCAPED_UNICODE);
    }
}
