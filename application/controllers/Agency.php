<?php
require_once("Home.php");

/**
 * Agency Control Center (Phase 11 / brief 31).
 *
 * One screen for Creative Monkey to watch every client account: channels, AI, leads,
 * people waiting for a human, questions the bot missed, AI cost, errors, and the shared
 * scheduler. Admin only — tenants never see each other; every figure is aggregated per
 * user_id and nothing here writes data.
 */
class Agency extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        if ($this->session->userdata('user_type') != 'Admin') redirect('home/access_forbidden', 'location');
    }

    public function index()
    {
        $days = 30;
        $since = date('Y-m-d H:i:s', time() - 86400 * $days);
        $since7 = date('Y-m-d H:i:s', time() - 86400 * 7);
        $now = date('Y-m-d H:i:s');

        $clients = $this->db->select('id, name, email, user_type, status, expired_date, package_id')
            ->from('users')->where('deleted', '0')->order_by('id', 'ASC')->get()->result_array();

        // per-tenant aggregates, one grouped query each (no N+1)
        $agg = array(
            'fb_pages'  => $this->_count_by_user('facebook_rx_fb_page_info', array('deleted' => '0', 'bot_enabled' => '1')),
            'ig_pages'  => $this->_count_by_user('facebook_rx_fb_page_info', array('deleted' => '0', 'bot_enabled' => '1', 'has_instagram' => '1')),
            'wa'        => $this->_count_by_user('openwa_accounts', array('status' => '1', 'bot_enabled' => '1')),
            'wa_cloud'  => $this->_count_by_user('whatsapp_accounts', array('status' => '1')),
            'tg'        => $this->_count_by_user('telegram_accounts', array('status' => '1')),
            'web'       => $this->_count_by_user('webchat_settings', array('status' => '1')),
            'msgs_in_7' => $this->_count_by_user('livechat_messages', array('sender' => 'user', 'conversation_time >=' => $since7)),
            'msgs_bot_7'=> $this->_count_by_user('livechat_messages', array('sender' => 'bot', 'conversation_time >=' => $since7)),
            'leads_30'  => $this->_count_by_user('crm_deals', array('created_at >=' => $since)),
            'missed'    => $this->_count_by_user('ai_unanswered_questions', array('status' => 'new')),
            'errors_7'  => $this->_count_by_user('messenger_bot_reply_error_log', array('error_time >=' => $since7)),
            'waiting'   => $this->_count_by_user('messenger_bot_subscriber', array('bot_paused_until >' => $now)),
            'guard_7'   => $this->_count_by_user('ai_price_guard_log', array('created_at >=' => $since7)),
        );
        $last_msg = $this->_max_by_user('livechat_messages', 'conversation_time');
        $ai_cost = $this->_ai_cost_by_user($since);
        $won = $this->_sum_by_user('crm_deals', 'value', array('status' => 'won', 'created_at >=' => $since));

        $rows = array();
        foreach ($clients as $c) {
            $u = (int) $c['id'];
            $g = function ($k) use ($agg, $u) { return isset($agg[$k][$u]) ? (int) $agg[$k][$u] : 0; };
            $channels = $g('fb_pages') + $g('wa') + $g('wa_cloud') + $g('tg') + $g('web');
            $last = $last_msg[$u] ?? null;
            $flags = array();
            if ($c['status'] !== '1') $flags[] = array('secondary', 'account inactive');
            if (!empty($c['expired_date']) && $c['expired_date'] < $now && $c['user_type'] !== 'Admin') $flags[] = array('danger', 'package expired');
            if ($channels > 0 && ($last === null || $last < $since7)) $flags[] = array('warning', 'no messages in 7 days');
            if ($g('waiting') > 0) $flags[] = array('danger', $g('waiting') . ' waiting for a human');
            if ($g('missed') >= 10) $flags[] = array('warning', $g('missed') . ' unanswered questions');
            if ($g('errors_7') > 0) $flags[] = array('warning', $g('errors_7') . ' send errors (7d)');
            $inb = $g('msgs_in_7'); $bot = $g('msgs_bot_7');
            $rows[] = array(
                'id' => $u, 'name' => $c['name'], 'email' => $c['email'], 'type' => $c['user_type'],
                'channels' => array('fb' => $g('fb_pages'), 'ig' => $g('ig_pages'), 'wa' => $g('wa') + $g('wa_cloud'), 'tg' => $g('tg'), 'web' => $g('web')),
                'msgs_in_7' => $inb, 'bot_reply_ratio' => $inb > 0 ? min(100, round($bot / $inb * 100)) : null,
                'last_message' => $last, 'leads_30' => $g('leads_30'), 'won_30' => (float) ($won[$u] ?? 0),
                'waiting' => $g('waiting'), 'missed' => $g('missed'), 'errors_7' => $g('errors_7'), 'guard_7' => $g('guard_7'),
                'ai_cost_30' => round((float) ($ai_cost[$u] ?? 0), 2), 'flags' => $flags,
            );
        }
        // attention first: most flags, then most inbound traffic
        usort($rows, function ($a, $b) { return (count($b['flags']) <=> count($a['flags'])) ?: ($b['msgs_in_7'] <=> $a['msgs_in_7']); });

        $this->load->helper('ops_health');
        $data['rows'] = $rows;
        $data['cron_age'] = ops_job_age_seconds('cron_hub');
        $data['totals'] = array(
            'clients' => count($rows),
            'active_channels' => array_sum(array_map(function ($r) { return array_sum($r['channels']) - $r['channels']['ig']; }, $rows)),
            'waiting' => array_sum(array_column($rows, 'waiting')),
            'missed' => array_sum(array_column($rows, 'missed')),
            'leads_30' => array_sum(array_column($rows, 'leads_30')),
            'ai_cost_30' => round(array_sum(array_column($rows, 'ai_cost_30')), 2),
        );
        // For the "New client" modal (brief §31/§32 — provisioning, read-only queries here
        // too: the write lives entirely in Agency_provision::provision_client()).
        $data['packages'] = $this->db->select('id, package_name, validity')->from('package')
            ->where('deleted', '0')->order_by('package_name', 'ASC')->get()->result_array();
        $this->load->helper('industry_template');
        $data['templates'] = industry_templates_all();

        $data['days'] = $days;
        $data['page_title'] = 'Agency Control Center';
        $data['body'] = 'admin/agency/index';
        $this->_viewcontroller($data);
    }

    /** @return array user_id => count; missing table => empty */
    private function _count_by_user($table, $where)
    {
        if (!$this->db->table_exists($table)) return array();
        foreach (array_keys($where) as $k) {
            $col = trim(preg_replace('/\s*(>=|<=|>|<|!=)$/', '', $k));
            if (!$this->db->field_exists($col, $table)) return array();
        }
        $out = array();
        foreach ($this->db->select('user_id, COUNT(*) c')->from($table)->where($where)->group_by('user_id')->get()->result_array() as $r)
            $out[(int) $r['user_id']] = (int) $r['c'];
        return $out;
    }

    private function _sum_by_user($table, $col, $where)
    {
        if (!$this->db->table_exists($table)) return array();
        $out = array();
        foreach ($this->db->select("user_id, SUM($col) s")->from($table)->where($where)->group_by('user_id')->get()->result_array() as $r)
            $out[(int) $r['user_id']] = (float) $r['s'];
        return $out;
    }

    private function _max_by_user($table, $col)
    {
        if (!$this->db->table_exists($table)) return array();
        $out = array();
        foreach ($this->db->select("user_id, MAX($col) m")->from($table)->group_by('user_id')->get()->result_array() as $r)
            $out[(int) $r['user_id']] = $r['m'];
        return $out;
    }

    /** Stored estimated_cost_usd where present (AI-cost accounting), else token × price table / defaults. */
    private function _ai_cost_by_user($since)
    {
        if (!$this->db->table_exists('ai_usage_log')) return array();
        $out = array();
        $has_cost = $this->db->field_exists('estimated_cost_usd', 'ai_usage_log');
        if ($has_cost) {
            foreach ($this->db->select('user_id, SUM(estimated_cost_usd) c')->from('ai_usage_log')->where('created_at >=', $since)
                ->where('estimated_cost_usd IS NOT NULL', null, false)->group_by('user_id')->get()->result_array() as $r)
                $out[(int) $r['user_id']] = (float) $r['c'];
        }
        $has_pricing = $this->db->table_exists('ai_model_pricing');
        $q = $this->db->select('l.user_id, l.model, SUM(l.input_tokens) i, SUM(l.output_tokens) o'
                . ($has_pricing ? ', MAX(p.input_per_1m_usd) pin, MAX(p.output_per_1m_usd) pout' : ''))
            ->from('ai_usage_log l');
        if ($has_pricing) $q->join('ai_model_pricing p', 'p.model = l.model AND p.provider = l.provider', 'left');
        $q->where('l.created_at >=', $since);
        if ($has_cost) $q->where('l.estimated_cost_usd IS NULL', null, false);
        foreach ($q->group_by('l.user_id, l.model')->get()->result_array() as $r) {
            $pin = isset($r['pin']) && $r['pin'] !== null ? (float) $r['pin'] : 1.0;
            $pout = isset($r['pout']) && $r['pout'] !== null ? (float) $r['pout'] : 3.0;
            $u = (int) $r['user_id'];
            $out[$u] = ($out[$u] ?? 0) + ($r['i'] / 1000000 * $pin) + ($r['o'] / 1000000 * $pout);
        }
        return $out;
    }
}
