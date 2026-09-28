<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controller for the read-only API v2 surface (Task 28 / C13, brief §37).
 *
 * application/controllers/api/v2/*.php extend this (require_once it first, the same
 * `require_once("Home.php")` pattern Customers.php/Crm.php use for their admin-UI base). It is
 * deliberately NOT under application/controllers/ itself — CodeIgniter's default routing over
 * subdirectories would otherwise try to route a request straight at it as if it were its own
 * controller (application/controllers/api/v2/Api_v2_controller.php would answer
 * api/v2/api_v2_controller/... ). Living in application/libraries/ keeps it unroutable while
 * still `require_once`-able.
 *
 * Every request to a subclass:
 *   1. Reads `Authorization: Bearer <token>`, hashes it (application/helpers/api_helper.php —
 *      the plaintext is never stored or compared), and looks it up in `api_tokens`.
 *   2. Rejects missing/unknown/revoked/expired tokens with 401 and an {error} envelope.
 *   3. Sets $this->uid to the token's user_id — that IS the tenant scope for every query a
 *      subclass runs; there is no session, no login, nothing else determines the tenant.
 *   4. Applies a simple per-token rate limit (api_rate_limit table, api_helper.php);
 *      429 when exceeded.
 *   5. Touches last_used_at (best-effort; failure here never blocks the request).
 *
 * Subclasses call $this->ok($data, $meta) / $this->fail($status, $message) to respond — both
 * always emit the {data, meta} / {error, meta} envelope and exit(). Nothing in this class or
 * its subclasses writes application data; this is read-only end to end (GET only, brief §37).
 */
class Api_v2_controller extends CI_Controller
{
    /** @var int tenant id — the authenticated token's user_id. Set by authenticate(). */
    public $uid;

    /** @var array the authenticated api_tokens row. */
    protected $api_token;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->load->helper('api');
        $this->authenticate();
        $this->enforce_rate_limit();
    }

    // ------------------------------------------------------------------ auth

    protected function authorization_header()
    {
        $header = $this->input->get_request_header('Authorization', true);
        if ($header) return $header;
        // Some SAPIs/proxies don't surface Authorization via get_request_header(); fall back to
        // the raw server var the same way other PHP apps do.
        $server = $this->input->server('HTTP_AUTHORIZATION');
        return $server ? $server : '';
    }

    protected function authenticate()
    {
        $result = api_token_authenticate($this->db, $this->authorization_header());
        if (!$result['ok']) {
            $this->fail(401, 'unauthorized: ' . $result['reason']);
        }

        $this->api_token = $result['token'];
        $this->uid = (int) $result['token']['user_id'];

        // Best-effort last_used_at touch — never let this block or fail the actual request.
        try {
            if (api_tokens_table_ready($this->db)) {
                $this->db->where('id', (int) $this->api_token['id'])
                    ->update('api_tokens', array('last_used_at' => date('Y-m-d H:i:s')));
            }
        } catch (\Throwable $e) {
            log_message('error', 'api_v2: last_used_at touch failed for token ' . (int) $this->api_token['id'] . ': ' . $e->getMessage());
        }
    }

    protected function enforce_rate_limit()
    {
        $limit = (int) $this->config->item('api_v2_rate_limit_per_minute');
        if ($limit <= 0) $limit = 60;

        if (!api_rate_limit_check($this->db, (int) $this->api_token['id'], $limit)) {
            $this->fail(429, 'rate limit exceeded: max ' . $limit . ' requests/minute');
        }
    }

    /** Scope gate for a subclass's area (e.g. 'conversations'); 403 when the token's scopes don't include it. */
    protected function require_scope($area)
    {
        if (!api_scope_allows($this->api_token['scopes'] ?? '*', $area)) {
            $this->fail(403, "token scope does not include '{$area}'");
        }
    }

    // ------------------------------------------------------------------ pagination helper

    protected function pagination_bounds($default = 20, $max = 100)
    {
        return api_pagination_bounds($this->input->get('page'), $this->input->get('per_page'), $default, $max);
    }

    // ------------------------------------------------------------------ output

    protected function ok($data, array $meta = array(), $status = 200)
    {
        $this->respond($status, api_envelope($data, $meta));
    }

    protected function fail($status, $message, array $meta = array())
    {
        $this->respond($status, api_error_envelope($message, $meta));
    }

    private function respond($status, array $payload)
    {
        $this->output
            ->set_status_header((int) $status)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
        exit();
    }
}
