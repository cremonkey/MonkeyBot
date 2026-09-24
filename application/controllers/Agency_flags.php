<?php
require_once("Home.php");

/**
 * Per-tenant feature flag toggles (Task 10 / brief B2, §50) — a write sibling to Agency.php,
 * same split as Agency_provision.php: Agency.php's own docblock promises "nothing here writes
 * data", so the one write action for flags lives here instead, admin-gated exactly like
 * Agency.php's constructor.
 */
class Agency_flags extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        if ($this->session->userdata('user_type') != 'Admin') redirect('home/access_forbidden', 'location');
        $this->load->helper('feature_flag');
    }

    /** No page of its own — this controller only answers the AJAX action below. */
    public function index()
    {
        redirect('agency', 'location');
    }

    /**
     * AJAX POST — set or clear one tenant's override for one flag.
     * Params: user_id (int, must be a real, non-deleted account), flag (string, [a-z0-9_]{1,64}),
     * action ('on' | 'off' | 'clear' — clear removes the override so the tenant falls back to
     * the global config item again).
     */
    public function toggle_flag()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $user_id = (int) $this->input->post('user_id', true);
        $flag = trim((string) $this->input->post('flag', true));
        $action = (string) $this->input->post('action', true);

        if ($user_id <= 0 || !$this->db->where('id', $user_id)->where('deleted', '0')->count_all_results('users')) {
            echo json_encode(array('status' => '0', 'message' => 'Unknown client.'), JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($flag === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $flag)) {
            echo json_encode(array('status' => '0', 'message' => 'Invalid flag name (lowercase letters, digits, underscore, max 64 chars).'), JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!in_array($action, array('on', 'off', 'clear'), true)) {
            echo json_encode(array('status' => '0', 'message' => 'Invalid action.'), JSON_UNESCAPED_UNICODE);
            return;
        }

        $enabled = $action === 'on' ? true : ($action === 'off' ? false : null);
        $ok = feature_flag_set($this->db, $user_id, $flag, $enabled);
        if (!$ok) {
            echo json_encode(array('status' => '0', 'message' => 'Feature flags table does not exist yet — run migrations.'), JSON_UNESCAPED_UNICODE);
            return;
        }

        $admin_id = (int) $this->session->userdata('user_id');
        log_message('error', 'audit: feature flag "' . $flag . '" for client ' . $user_id . ' set to '
            . ($enabled === null ? 'CLEAR (global default)' : ($enabled ? 'ON' : 'OFF')) . ' by admin ' . $admin_id);

        echo json_encode(array('status' => '1', 'message' => 'Saved.', 'flag' => $flag, 'action' => $action), JSON_UNESCAPED_UNICODE);
    }
}
