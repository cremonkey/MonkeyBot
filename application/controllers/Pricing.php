<?php
require_once("Home.php");

/**
 * Products & Pricing (Phase 10 / brief 21).
 *
 * Owner-editable version of pricing_config — the data the calculate_price AI tool uses.
 * The AI never invents prices: it calls the tool, the tool computes from this config,
 * and the price guard treats the result as authoritative. This page is where the
 * business keeps those numbers correct, with validation, a test calculator that runs the
 * exact production code on the unsaved draft, and an audit history.
 */
class Pricing extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper('entitlement');
        entitlement_require('pricing');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->load->helper(array('pricing', 'media'));
    }

    public function index()
    {
        $row = $this->db->table_exists('pricing_config')
            ? $this->db->from('pricing_config')->where('user_id', $this->uid)->get()->row_array() : null;
        $cfg = !empty($row['config_json']) ? json_decode($row['config_json'], true) : null;
        if (!is_array($cfg)) {
            $cfg = array(
                'currency' => 'جنيه',
                'accommodation' => array('rooms' => array(), 'note' => ''),
                'day_use' => array('packages' => array(), 'nth_free' => null),
                'children' => array('free_max_age' => 5, 'free_cap' => 2, 'half_min_age' => 6, 'half_max_age' => 11, 'full_min_age' => 12, 'half_price_known' => false),
            );
        }
        $data['cfg'] = $cfg;
        $data['active'] = !empty($row) && ($row['status'] ?? '0') === '1';
        $data['updated_at'] = $row['updated_at'] ?? null;
        $data['history'] = $this->db->table_exists('pricing_config_history')
            ? $this->db->select('h.id, h.summary, h.created_at, u.name')
                ->from('pricing_config_history h')->join('users u', 'u.id = h.changed_by', 'left')
                ->where('h.user_id', $this->uid)->order_by('h.id', 'DESC')->limit(15)->get()->result_array()
            : array();
        $data['page_title'] = 'Products & Pricing';
        $data['body'] = 'admin/pricing/index';
        $this->_viewcontroller($data);
    }

    /** AJAX: validate + save the whole config. */
    public function save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $cfg = json_decode((string) $this->input->post('config_json'), true);
        $summary = trim(strip_tags((string) $this->input->post('summary', true)));
        list($ok, $errors) = pricing_save_config($this->uid, $cfg, (int) $this->session->userdata('user_id'), $summary);
        echo json_encode($ok ? array('status' => '1', 'message' => 'Prices saved. The AI uses them from the next message.')
                             : array('status' => '0', 'errors' => $errors));
    }

    /** AJAX: run the production calculator against the (unsaved) draft. */
    public function calc()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $cfg = json_decode((string) $this->input->post('config_json'), true);
        $errors = pricing_validate_config($cfg);
        if (!empty($errors)) { echo json_encode(array('status' => '0', 'errors' => $errors)); return; }
        $kind = $this->input->post('kind', true) === 'day_use' ? 'day_use' : 'accommodation';
        $item = trim((string) $this->input->post('item', true));
        $adults = (int) $this->input->post('adults'); $c_free = (int) $this->input->post('c_free');
        $c_half = (int) $this->input->post('c_half'); $c_full = (int) $this->input->post('c_full');
        $res = $kind === 'day_use'
            ? pricing_calc_day_use($cfg, $item, $adults, $c_free, $c_half, $c_full)
            : pricing_calc_accommodation($cfg, $item, (int) $this->input->post('nights'), $adults, $c_free, $c_half, $c_full);
        echo json_encode(array('status' => '1', 'result' => $res), JSON_UNESCAPED_UNICODE);
    }

    /** AJAX: one history entry (before/after) for the diff view. */
    public function history_entry($id = 0)
    {
        $this->ajax_check();
        header('Content-Type: application/json');
        $h = $this->db->from('pricing_config_history')->where('id', (int) $id)->where('user_id', $this->uid)->get()->row_array();
        if (!$h) { echo json_encode(array('status' => '0')); return; }
        echo json_encode(array('status' => '1', 'before' => $h['before_json'], 'after' => $h['after_json']), JSON_UNESCAPED_UNICODE);
    }

    /** AJAX: turn the calculator tool on/off for this account without deleting data. */
    public function toggle()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        $on = $this->input->post('active') === '1' ? '1' : '0';
        $this->db->where('user_id', $this->uid)->update('pricing_config', array('status' => $on, 'updated_at' => date('Y-m-d H:i:s')));
        echo json_encode(array('status' => '1', 'active' => $on));
    }
}
