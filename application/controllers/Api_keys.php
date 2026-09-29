<?php
require_once("Home.php");

/**
 * API keys (follow-up to Task 28 / C13, brief §37) — tenant-facing create/list/revoke for the
 * personal-access tokens application/helpers/api_helper.php and
 * application/libraries/Api_v2_controller.php already know how to authenticate. Before this
 * controller, api_token_generate() was called from nowhere: a tenant had no way to ever get a
 * token, so the read-only API v2 surface (application/controllers/api/v2/*.php) was unusable.
 *
 * Follows the Sequences.php / Customers.php pattern: entitlement_require() in the constructor,
 * ajax_check() + csrf_token_check() on every POST, every query scoped to $this->uid. The
 * plaintext token is only ever present in the create response — it is never stored (only
 * token_hash is) and never re-shown after that one response.
 *
 * Table `api_tokens` — assets/backup_db/migrations/2026-10-04g-api-tokens.sql. Guarded by
 * api_tokens_table_ready() (api_helper.php) so this controller doesn't fatal before the
 * migration has run: list shows empty, create/revoke fail with a clear JSON error.
 */
class Api_keys extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper(array('entitlement', 'api', 'audit'));
        entitlement_require('api');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
    }

    // ------------------------------------------------------------------ page

    public function index()
    {
        $data['tokens'] = $this->tokens_ready() ? $this->db->select('id, name, created_at, last_used_at, expires_at, revoked_at')
            ->from('api_tokens')->where('user_id', $this->uid)->order_by('id', 'DESC')->get()->result_array() : array();
        $data['table_ready'] = $this->tokens_ready();
        $data['page_title'] = 'API keys';
        $data['body'] = 'admin/api_keys/index';
        $this->_viewcontroller($data);
    }

    // ------------------------------------------------------------------ ajax

    /** Create a token. Plaintext is only ever returned here — never stored, never re-shown. */
    public function create_token()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        if (!$this->tokens_ready()) {
            echo json_encode(array('status' => '0', 'message' => 'API tokens are not available yet. Try again shortly.'));
            return;
        }

        $name = trim((string) $this->input->post('name', true));
        if ($name === '') {
            echo json_encode(array('status' => '0', 'message' => 'Name is required.'));
            return;
        }
        if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);

        $expires_at = null;
        $expires_days = (int) $this->input->post('expires_days');
        if ($expires_days > 0) {
            $expires_at = date('Y-m-d H:i:s', strtotime('+' . $expires_days . ' days'));
        }

        $generated = api_token_generate();

        $row = array(
            'user_id'    => $this->uid,
            'token_hash' => $generated['hash'],
            'name'       => $name,
            'scopes'     => '*',
            'expires_at' => $expires_at,
            'created_at' => date('Y-m-d H:i:s'),
        );
        $this->db->insert('api_tokens', $row);
        $id = (int) $this->db->insert_id();

        audit_record($this->uid, 'api_token.created', 'api_token', $id, null, array('name' => $name, 'expires_at' => $expires_at));

        echo json_encode(array(
            'status' => '1',
            'token'  => array(
                'id'         => $id,
                'name'       => $name,
                'plaintext'  => $generated['plaintext'], // shown exactly once; never stored, never returned again
                'expires_at' => $expires_at,
                'created_at' => $row['created_at'],
            ),
        ));
    }

    /** Revoke (soft-delete) a token owned by this tenant. Cross-tenant ids are refused. */
    public function revoke_token()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        if (!$this->tokens_ready()) {
            echo json_encode(array('status' => '0', 'message' => 'API tokens are not available yet.'));
            return;
        }

        $id = (int) $this->input->post('id');
        $token = $this->owned_token($id);
        if (!$token) {
            echo json_encode(array('status' => '0', 'message' => 'Token not found.'));
            return;
        }
        if (!empty($token['revoked_at'])) {
            echo json_encode(array('status' => '1')); // already revoked — idempotent
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->where('id', $id)->where('user_id', $this->uid)->update('api_tokens', array('revoked_at' => $now));

        audit_record($this->uid, 'api_token.revoked', 'api_token', $id, array('revoked_at' => null), array('revoked_at' => $now));

        echo json_encode(array('status' => '1'));
    }

    /** AJAX: refresh the list (name, created, last used, revoked — never the token). */
    public function tokens_data()
    {
        $this->ajax_check();
        header('Content-Type: application/json');

        $rows = $this->tokens_ready() ? $this->db->select('id, name, created_at, last_used_at, expires_at, revoked_at')
            ->from('api_tokens')->where('user_id', $this->uid)->order_by('id', 'DESC')->get()->result_array() : array();

        echo json_encode(array('status' => '1', 'rows' => $rows));
    }

    // ------------------------------------------------------------------ helpers

    private function tokens_ready()
    {
        static $ready = null;
        if ($ready === null) $ready = (bool) $this->db->table_exists('api_tokens');
        return $ready;
    }

    /** Tenant-owned lookup by id, never returns another tenant's row. */
    private function owned_token($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        return $this->db->select('id, user_id, name, created_at, last_used_at, expires_at, revoked_at')
            ->from('api_tokens')->where('id', $id)->where('user_id', $this->uid)->limit(1)->get()->row_array() ?: null;
    }
}
