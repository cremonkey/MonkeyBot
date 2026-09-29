<?php
require_once("Home.php");

/**
 * Products & Pricing (Phase 10 / brief 21; per-page lists 2026-09-29).
 *
 * Owner-editable version of pricing_config — the data the calculate_price AI tool uses.
 * The AI never invents prices: it calls the tool, the tool computes from this config,
 * and the price guard treats the result as authoritative. This page is where the
 * business keeps those numbers correct, with validation, a test calculator that runs the
 * exact production code on the unsaved draft, and an audit history.
 *
 * Every list belongs to a scope: one connected page / number / widget, or the
 * account-wide default list ("All pages"). The AI on a page uses that page's own list,
 * else the default list (pricing_get_config). Every endpoint takes the scope from the
 * request and refuses one that is not this tenant's own connection (IDOR).
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
        $this->load->helper(array('pricing', 'media', 'ai_agent'));
    }

    /**
     * Strict scope parsing for requests: '' = the default list; "ch:target" must be a
     * valid channel AND one of this tenant's own connections. Anything else = false.
     */
    private function _scope_from($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return array('', '');
        $scope = pricing_normalize_scope($raw);
        if ($scope[0] === '' || !pricing_target_owned($this->uid, $scope)) return false;
        return $scope;
    }

    private function _labels()
    {
        $labels = array('' => 'All pages (default list)');
        foreach (pricing_connected_targets($this->uid) as $t) $labels[$t['scope']] = $t['label'] . ($t['note'] !== '' ? ' — ' . $t['note'] : '');
        return $labels;
    }

    private function _json($a)
    {
        header('Content-Type: application/json');
        echo json_encode($a, JSON_UNESCAPED_UNICODE);
    }

    public function index()
    {
        $warning = '';
        $scope = $this->_scope_from($this->input->get('scope', true));
        if ($scope === false) { $scope = array('', ''); $warning = 'That page is not connected to your account, so the default list is shown.'; }
        $key = pricing_scope_key($scope);

        $scoped = pricing_has_scope_columns();
        $rows = array();
        if ($this->db->table_exists('pricing_config')) {
            $this->db->select($scoped ? 'channel_type, target_id, status' : 'status')->from('pricing_config')->where('user_id', $this->uid);
            foreach ($this->db->get()->result_array() as $r) {
                $rows[$scoped ? pricing_scope_key(array($r['channel_type'], $r['target_id'])) : ''] = $r['status'];
            }
        }
        $targets = pricing_connected_targets($this->uid);
        foreach ($targets as &$t) $t['has_list'] = isset($rows[$t['scope']]);
        unset($t);

        $row = pricing_get_row($this->uid, $scope);
        $cfg = !empty($row['config_json']) ? json_decode($row['config_json'], true) : null;
        if (!is_array($cfg)) $cfg = array('currency' => 'جنيه', 'catalog' => array('items' => array()));

        $labels = $this->_labels();
        $data['cfg'] = $cfg;
        $data['has_own'] = !empty($row);
        $data['active'] = !empty($row) && ($row['status'] ?? '0') === '1';
        $data['updated_at'] = $row['updated_at'] ?? null;
        $data['scope_key'] = $key;
        $data['scope_label'] = $labels[$key] ?? $key;
        $data['targets'] = $targets;
        $data['default_exists'] = isset($rows['']);
        $data['default_active'] = isset($rows['']) && $rows[''] === '1';
        $data['scoped'] = $scoped;
        $data['warning'] = $warning;

        $hist = array();
        if ($this->db->table_exists('pricing_config_history')) {
            $hscoped = $scoped && $this->db->field_exists('channel_type', 'pricing_config_history');
            $hist = $this->db->select('h.id, h.summary, h.created_at, u.name' . ($hscoped ? ', h.channel_type, h.target_id' : ''))
                ->from('pricing_config_history h')->join('users u', 'u.id = h.changed_by', 'left')
                ->where('h.user_id', $this->uid)->order_by('h.id', 'DESC')->limit(20)->get()->result_array();
            foreach ($hist as &$h) {
                $hk = $hscoped ? pricing_scope_key(array($h['channel_type'], $h['target_id'])) : '';
                $h['for'] = $labels[$hk] ?? 'A page that is no longer connected';
            }
            unset($h);
        }
        $data['history'] = $hist;
        $data['page_title'] = 'Products & Pricing';
        $data['body'] = 'admin/pricing/index';
        $this->_viewcontroller($data);
    }

    /** AJAX: validate + save the whole config for one scope. */
    public function save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $scope = $this->_scope_from($this->input->post('scope', true));
        if ($scope === false) { $this->_json(array('status' => '0', 'errors' => array('That page is not connected to your account.'))); return; }
        $cfg = json_decode((string) $this->input->post('config_json'), true);
        $summary = trim(strip_tags((string) $this->input->post('summary', true)));
        list($ok, $errors) = pricing_save_config($this->uid, $cfg, (int) $this->session->userdata('user_id'), $summary, $scope);
        $this->_json($ok ? array('status' => '1', 'message' => 'Prices saved. The AI uses them from the next message.')
                         : array('status' => '0', 'errors' => $errors));
    }

    /** AJAX: run the production calculator against the (unsaved) draft. */
    public function calc()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $cfg = json_decode((string) $this->input->post('config_json'), true);
        $errors = pricing_validate_config($cfg);
        if (!empty($errors)) { $this->_json(array('status' => '0', 'errors' => $errors)); return; }
        $kind = (string) $this->input->post('kind', true);
        $item = trim((string) $this->input->post('item', true));
        $coupon = trim((string) $this->input->post('coupon_code', true));
        $coupon = $coupon !== '' ? $coupon : null;
        if ($kind === 'item') {
            $res = pricing_calc_item($cfg, $item, trim((string) $this->input->post('variant', true)), $this->input->post('quantity', true), $coupon);
            $this->_json(array('status' => '1', 'result' => $res));
            return;
        }
        if ($kind === 'package') {
            $res = pricing_calc_package($cfg, $item, $this->input->post('quantity', true), $coupon);
            $this->_json(array('status' => '1', 'result' => $res));
            return;
        }
        $adults = (int) $this->input->post('adults'); $c_free = (int) $this->input->post('c_free');
        $c_half = (int) $this->input->post('c_half'); $c_full = (int) $this->input->post('c_full');
        $checkin = trim((string) $this->input->post('checkin_date', true));
        $checkin = $checkin !== '' ? $checkin : null;
        $res = $kind === 'day_use'
            ? pricing_calc_day_use($cfg, $item, $adults, $c_free, $c_half, $c_full, $coupon)
            : pricing_calc_accommodation($cfg, $item, (int) $this->input->post('nights'), $adults, $c_free, $c_half, $c_full, $checkin, $coupon);
        $this->_json(array('status' => '1', 'result' => $res));
    }

    /** AJAX: one history entry (before/after) for the diff view. */
    public function history_entry($id = 0)
    {
        $this->ajax_check();
        $h = $this->db->from('pricing_config_history')->where('id', (int) $id)->where('user_id', $this->uid)->get()->row_array();
        if (!$h) { $this->_json(array('status' => '0')); return; }
        $this->_json(array('status' => '1', 'before' => $h['before_json'], 'after' => $h['after_json']));
    }

    /** AJAX: turn one list on/off without deleting data. */
    public function toggle()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $scope = $this->_scope_from($this->input->post('scope', true));
        if ($scope === false) { $this->_json(array('status' => '0', 'message' => 'That page is not connected to your account.')); return; }
        $on = $this->input->post('active') === '1' ? '1' : '0';
        $row = pricing_get_row($this->uid, $scope);
        if (empty($row)) { $this->_json(array('status' => '0', 'message' => 'Save this list first.')); return; }
        $this->db->where('id', $row['id'])->where('user_id', $this->uid)->update('pricing_config', array('status' => $on, 'updated_at' => date('Y-m-d H:i:s')));
        $this->_json(array('status' => '1', 'active' => $on));
    }

    /** AJAX: copy the list shown (scope) onto another page (to). */
    public function copy()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $from = $this->_scope_from($this->input->post('scope', true));
        $to = $this->_scope_from($this->input->post('to', true));
        if ($from === false || $to === false) { $this->_json(array('status' => '0', 'errors' => array('That page is not connected to your account.'))); return; }
        $labels = $this->_labels();
        list($ok, $errors, $code) = pricing_copy_config($this->uid, $from, $to, (int) $this->session->userdata('user_id'),
            $this->input->post('overwrite') === '1', 'Copied from ' . ($labels[pricing_scope_key($from)] ?? 'another list'));
        $this->_json($ok ? array('status' => '1', 'message' => 'Copied. That page now has its own list.', 'scope' => pricing_scope_key($to))
                         : array('status' => '0', 'code' => $code, 'errors' => $errors));
    }

    /** AJAX: move the default list to one page (e.g. attach the hotel list to the hotel's page). */
    public function move()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $to = $this->_scope_from($this->input->post('to', true));
        if ($to === false || $to[0] === '') { $this->_json(array('status' => '0', 'errors' => array('Pick one of your connected pages.'))); return; }
        $labels = $this->_labels();
        list($ok, $errors, $code) = pricing_move_config($this->uid, array('', ''), $to, (int) $this->session->userdata('user_id'),
            $this->input->post('overwrite') === '1', 'Default list moved to ' . ($labels[pricing_scope_key($to)] ?? 'a page'));
        $this->_json($ok ? array('status' => '1', 'message' => 'Moved. Only that page uses this list now.', 'scope' => pricing_scope_key($to))
                         : array('status' => '0', 'code' => $code, 'errors' => $errors));
    }

    /** AJAX: remove a page's own list; the page falls back to the default list. */
    public function remove()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $scope = $this->_scope_from($this->input->post('scope', true));
        if ($scope === false || $scope[0] === '') { $this->_json(array('status' => '0', 'errors' => array('Pick one of your connected pages.'))); return; }
        list($ok, $errors) = pricing_delete_config($this->uid, $scope, (int) $this->session->userdata('user_id'), 'Removed this page\'s own list');
        $this->_json($ok ? array('status' => '1', 'message' => 'Removed. This page uses the default list now.')
                         : array('status' => '0', 'errors' => $errors));
    }
}
