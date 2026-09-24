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

        $this->load->helper('agency_billing');
        $this->load->helper('agency_health');
        // brief §11: usage vs PACKAGE LIMITS. package.monthly_limit is the same JSON shape/semantics
        // Home::_check_usage() and the admin package screens already use (module_id -> limit, 0/absent
        // = unlimited) — read here, never re-defined. Module 340 "Bot - AI Reply" is the readiness
        // metric shown (brief: "e.g. message count, AI calls"); it is a real modules.id from
        // assets/backup_db/initial_db.sql, not an invented one.
        $usage_module_id = 340;
        $packages_by_id = $this->_packages_by_id();
        $usage_module = $this->db->select('extra_text')->from('modules')->where('id', $usage_module_id)->get()->row_array();
        $usage_is_monthly = !empty($usage_module) && agency_billing_is_module_monthly($usage_module['extra_text']);
        $usage_by_user = $this->_module_usage_by_user($usage_module_id, $usage_is_monthly);

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
            // A5: "waiting" is the real handoff state now (conversations.handoff_state), not the
            // legacy bot-pause timer. bot_paused_until is kept as a second column until D1 lands.
            'waiting_paused_legacy' => $this->_count_by_user('messenger_bot_subscriber', array('bot_paused_until >' => $now)),
            'guard_7'   => $this->_count_by_user('ai_price_guard_log', array('created_at >=' => $since7)),
        );
        $waiting_by_user = agency_health_waiting_by_user($this->db);
        $crm_push_by_user = agency_health_crm_push_by_user($this->db);
        $sheets_by_user = agency_health_sheets_failures_by_user($this->db);
        $inbound_by_platform = agency_health_last_inbound_by_platform($this->db);
        $last_msg = $this->_max_by_user('livechat_messages', 'conversation_time');
        $ai_cost = $this->_ai_cost_by_user($since);
        $won = $this->_sum_by_user('crm_deals', 'value', array('status' => 'won', 'created_at >=' => $since));

        $rows = array();
        foreach ($clients as $c) {
            $u = (int) $c['id'];
            $g = function ($k) use ($agg, $u) { return isset($agg[$k][$u]) ? (int) $agg[$k][$u] : 0; };
            $channels = $g('fb_pages') + $g('wa') + $g('wa_cloud') + $g('tg') + $g('web');
            $last = $last_msg[$u] ?? null;
            $waiting = isset($waiting_by_user[$u]) ? $waiting_by_user[$u] : 0;
            $crm_push = isset($crm_push_by_user[$u]) ? $crm_push_by_user[$u] : array('pending' => 0, 'failed' => 0, 'last_failure' => null);
            $sheets = isset($sheets_by_user[$u]) ? $sheets_by_user[$u] : array('failing' => false, 'last_error' => null, 'last_synced_at' => null);
            $last_inbound = isset($inbound_by_platform[$u]) ? $inbound_by_platform[$u] : array();
            $flags = array();
            if ($c['status'] !== '1') $flags[] = array('secondary', 'account inactive');
            if (!empty($c['expired_date']) && $c['expired_date'] < $now && $c['user_type'] !== 'Admin') $flags[] = array('danger', 'package expired');
            if ($channels > 0 && ($last === null || $last < $since7)) $flags[] = array('warning', 'no messages in 7 days');
            if ($waiting > 0) $flags[] = array('danger', $waiting . ' waiting for a human');
            if ($g('missed') >= 10) $flags[] = array('warning', $g('missed') . ' unanswered questions');
            if ($g('errors_7') > 0) $flags[] = array('warning', $g('errors_7') . ' send errors (7d)');
            if ($crm_push['failed'] > 0) $flags[] = array('warning', $crm_push['failed'] . ' CRM push failures');
            if ($sheets['failing']) $flags[] = array('warning', 'Sheets sync failing');
            $inb = $g('msgs_in_7'); $bot = $g('msgs_bot_7');

            // brief §11: package name + expiry readiness + usage vs monthly_limit.
            $pkg = isset($packages_by_id[(int) $c['package_id']]) ? $packages_by_id[(int) $c['package_id']] : null;
            $days_left = $c['user_type'] === 'Admin' ? null : agency_billing_days_until_expiry($c['expired_date'], $now);
            $expiry = agency_billing_expiry_status($days_left);
            $limits = $pkg ? agency_billing_decode_monthly_limit($pkg['monthly_limit']) : array();
            $usage = agency_billing_usage_percent(
                isset($limits[$usage_module_id]) ? $limits[$usage_module_id] : null,
                isset($usage_by_user[$u]) ? $usage_by_user[$u] : 0
            );
            if ($expiry['flag'] === 'danger' && $c['user_type'] !== 'Admin') $flags[] = array('danger', $expiry['label']);
            if (!$usage['unlimited'] && $usage['percent'] >= 90) $flags[] = array('warning', 'AI usage at ' . $usage['percent'] . '% of package limit');

            $rows[] = array(
                'id' => $u, 'name' => $c['name'], 'email' => $c['email'], 'type' => $c['user_type'],
                'channels' => array('fb' => $g('fb_pages'), 'ig' => $g('ig_pages'), 'wa' => $g('wa') + $g('wa_cloud'), 'tg' => $g('tg'), 'web' => $g('web')),
                'msgs_in_7' => $inb, 'bot_reply_ratio' => $inb > 0 ? min(100, round($bot / $inb * 100)) : null,
                'last_message' => $last, 'leads_30' => $g('leads_30'), 'won_30' => (float) ($won[$u] ?? 0),
                'waiting' => $waiting, 'waiting_paused_legacy' => $g('waiting_paused_legacy'),
                'missed' => $g('missed'), 'errors_7' => $g('errors_7'), 'guard_7' => $g('guard_7'),
                'ai_cost_30' => round((float) ($ai_cost[$u] ?? 0), 2), 'flags' => $flags,
                'package_name' => $pkg ? $pkg['package_name'] : '—',
                'days_until_expiry' => $days_left, 'expiry' => $expiry,
                'usage' => $usage,
                'integration_health' => array(
                    'crm_pending' => $crm_push['pending'], 'crm_failed' => $crm_push['failed'],
                    'crm_last_failure' => $crm_push['last_failure'],
                    'sheets_failing' => $sheets['failing'], 'sheets_last_error' => $sheets['last_error'],
                    'sheets_last_synced_at' => $sheets['last_synced_at'],
                    'last_inbound' => $last_inbound,
                ),
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
            'renewals_due_soon' => count(array_filter($rows, function ($r) { return $r['type'] !== 'Admin' && agency_billing_is_renewal_due_soon($r['days_until_expiry']); })),
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

    /** @return array package_id => {package_name, monthly_limit (raw JSON string)} */
    private function _packages_by_id()
    {
        $out = array();
        foreach ($this->db->select('id, package_name, monthly_limit')->from('package')->get()->result_array() as $p)
            $out[(int) $p['id']] = $p;
        return $out;
    }

    /**
     * usage_log summed per tenant for one module (brief §11 readiness). Mirrors
     * Home::_check_usage()'s own two branches exactly: month-scoped (usage_month/usage_year =
     * now) when the module is "monthly" (modules.extra_text non-empty), otherwise the
     * cumulative total ever recorded. @return array user_id => usage_count
     */
    private function _module_usage_by_user($module_id, $is_monthly)
    {
        if (!$this->db->table_exists('usage_log')) return array();
        $this->db->select('user_id, SUM(usage_count) c')->from('usage_log')->where('module_id', $module_id);
        if ($is_monthly) $this->db->where('usage_month', (int) date('n'))->where('usage_year', (int) date('Y'));
        $out = array();
        foreach ($this->db->group_by('user_id')->get()->result_array() as $r) $out[(int) $r['user_id']] = (int) $r['c'];
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
