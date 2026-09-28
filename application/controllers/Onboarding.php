<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Phase 11 / brief §32, §33, §46, §65 — the Setup Guide (client onboarding wizard).
 *
 * WHAT THIS IS NOT: a second copy of any feature. Every step drives an EXISTING feature
 * through its existing save path —
 *   AI Employee  -> ai_agent_profiles (+ ai_agent_assignments for channels with none yet)
 *   Knowledge    -> ai_faq rows, keyed by source_key so re-applying never duplicates
 *   Prices       -> pricing_save_config() per chosen page (validated + audited, same as /pricing)
 *   Pipeline     -> crm_ensure_pipeline() + crm_stages
 *   Working mode -> the hours_* columns, normalised by working_hours_normalize_time()
 *   Test         -> ai_agent_preview_reply(), the same call /ai_agents/preview makes
 * Channels only LINKS to the existing connect pages and reports live status.
 *
 * Every step is idempotent: re-running updates in place, never duplicates. The wizard
 * never deletes anything — not a FAQ, not a stage, not a price, not an assignment.
 *
 * Owner language (§46): no module ids, no "RAG", no "webhook", no "cron" in the UI.
 */
class Onboarding extends Home
{
    const STEPS = 10;

    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper('entitlement');
        entitlement_require('onboarding');
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        $this->load->helper(array('industry_template', 'ai_agent', 'pricing', 'working_hours', 'crm'));
    }

    /* ------------------------------------------------------------------ state */

    /** The tenant's wizard state, created on first read. Tenant-scoped by PK. */
    private function state()
    {
        $row = $this->db->from('onboarding_state')->where('user_id', $this->uid)->get()->row_array();
        if (empty($row)) {
            $this->db->query(
                'INSERT INTO onboarding_state (user_id, current_step, data_json, updated_at) VALUES (?,1,?,?) '
                . 'ON DUPLICATE KEY UPDATE user_id = user_id',
                array((int) $this->uid, '{}', date('Y-m-d H:i:s'))
            );
            $row = $this->db->from('onboarding_state')->where('user_id', $this->uid)->get()->row_array();
        }
        $row['data'] = json_decode((string) ($row['data_json'] ?? ''), true);
        if (!is_array($row['data'])) $row['data'] = array();
        return $row;
    }

    /** Merge $patch into data_json and (optionally) advance the furthest step reached. */
    private function save_state($patch = array(), $step_done = 0, $template_key = null)
    {
        $st = $this->state();
        $data = array_merge($st['data'], $patch);
        $set = array(
            'data_json'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        if ($template_key !== null) $set['template_key'] = $template_key;
        // current_step = the furthest step the owner has reached; it never walks backwards,
        // so re-running an early step does not lose progress.
        $next = min(self::STEPS, max(1, (int) $step_done + 1));
        if ($step_done > 0 && $next > (int) $st['current_step']) $set['current_step'] = $next;
        $this->db->where('user_id', $this->uid)->update('onboarding_state', $set);
        return $this->state();
    }

    private function template_of($st)
    {
        return empty($st['template_key']) ? null : industry_template_load($st['template_key']);
    }

    private function jout($arr)
    {
        header('Content-Type: application/json');
        echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    }

    /* ------------------------------------------------------------------ pages */

    public function index()
    {
        $st = $this->state();
        redirect('onboarding/step/' . (int) $st['current_step'], 'location');
    }

    public function step($n = 1)
    {
        $n = max(1, min(self::STEPS, (int) $n));
        $st = $this->state();
        $tpl = $this->template_of($st);

        // Step 1 is the only step that can run without a template.
        if ($n > 1 && $tpl === null) redirect('onboarding/step/1', 'location');
        // Steps 3+ change live behaviour, so they only run once the owner has said which
        // pages belong to this business.
        if ($n > 2 && empty($this->selected_targets($st))) redirect('onboarding/step/2', 'location');

        $data = array(
            'step' => $n,
            'steps_total' => self::STEPS,
            'step_titles' => $this->step_titles(),
            'state' => $st,
            'tpl' => $tpl,
            'templates' => industry_templates_all(),
            'progress' => $this->progress($st, $tpl),
        );

        switch ($n) {
            case 2:
                $data['channels'] = $this->targets_all();
                $data['selected'] = $this->selected_targets($st);
                $data['connect_links'] = $this->connect_links();
                $data['assigned_to'] = array();   // "channel:target" => agent name already answering there
                foreach ($this->db->select('a.channel_type, a.target_id, p.agent_name, p.name')
                    ->from('ai_agent_assignments a')->join('ai_agent_profiles p', 'p.id = a.profile_id', 'left')
                    ->where('a.user_id', $this->uid)->get()->result_array() as $a) {
                    $data['assigned_to'][$a['channel_type'] . ':' . $a['target_id']] = $a['agent_name'] ?: $a['name'];
                }
                break;
            case 3:
                $data['profile'] = $this->wizard_profile($st);
                break;
            case 4:
                // One row per starter question (the first selected page's copy) — every
                // selected page carries the same answer.
                $data['faq_rows'] = array();
                foreach ($this->db->from('ai_faq')->where('user_id', $this->uid)
                    ->like('source_key', 'tpl:', 'after')->like('source_key', '@', 'both')
                    ->order_by('id', 'ASC')->get()->result_array() as $r) {
                    $base = explode('@', (string) $r['source_key'])[0];
                    if (!isset($data['faq_rows'][$base])) { $r['source_key'] = $base; $data['faq_rows'][$base] = $r; }
                }
                $data['faq_rows'] = array_values($data['faq_rows']);
                break;
            case 5:
                // Step 5 writes to the pages chosen in step 2 only; the form starts from the
                // template skeleton, and pages that already have a list are shown, not edited.
                $data['pages'] = $this->pricing_pages($this->selected_targets($st));
                $data['lists_total'] = $this->pricing_lists_total();
                $data['cfg'] = $tpl['pricing_config'] ?? null;
                $data['units'] = pricing_catalog_units();
                $data['can_pricing'] = entitlement_allows('pricing');   // link only to a page the member can open
                break;
            case 6:
                $pid = $this->db->from('crm_pipelines')->where('user_id', $this->uid)->order_by('id', 'ASC')->limit(1)->get()->row_array();
                $data['pipeline'] = $pid;
                $data['stages'] = empty($pid) ? array()
                    : $this->db->from('crm_stages')->where('pipeline_id', $pid['id'])->order_by('position', 'ASC')->get()->result_array();
                break;
            case 7:
                $data['hours'] = $this->current_hours($this->selected_targets($st));
                break;
            case 8:
                $data['profile'] = $this->wizard_profile($st);
                $data['ai_ready'] = $this->ai_ready();
                break;
            case 9:
                $data['checklist'] = $this->checklist($st, $tpl);
                break;
            case 10:
                $data['extras'] = $this->extras_state($st, $tpl);
                break;
        }

        $data['page_title'] = 'Setup Guide';
        $data['body'] = 'admin/onboarding/step' . $n;
        $this->_viewcontroller($data);
    }

    private function step_titles()
    {
        return array(
            1 => array('en' => 'Business type', 'ar' => 'نوع النشاط'),
            2 => array('en' => 'Channels', 'ar' => 'قنوات التواصل'),
            3 => array('en' => 'AI Employee', 'ar' => 'الموظف الذكي'),
            4 => array('en' => 'Knowledge', 'ar' => 'المعلومات'),
            5 => array('en' => 'Products / Pricing', 'ar' => 'المنتجات والأسعار'),
            6 => array('en' => 'Lead pipeline', 'ar' => 'مراحل العملاء'),
            7 => array('en' => 'Working mode', 'ar' => 'طريقة الشغل'),
            8 => array('en' => 'Test conversation', 'ar' => 'جرّب محادثة'),
            9 => array('en' => 'Go live', 'ar' => 'ابدأ الشغل'),
            10 => array('en' => 'Extras (optional)', 'ar' => 'إضافات اختيارية'),
        );
    }

    /** Links to the EXISTING connect pages — the wizard never rebuilds a connection flow. */
    private function connect_links()
    {
        return array(
            array('label' => 'Facebook & Instagram', 'ar' => 'فيسبوك وإنستجرام', 'url' => 'social_accounts', 'icon' => 'fab fa-facebook'),
            array('label' => 'WhatsApp (Business API)', 'ar' => 'واتساب (الرسمي)', 'url' => 'whatsapp_bot', 'icon' => 'fab fa-whatsapp'),
            array('label' => 'WhatsApp (personal number)', 'ar' => 'واتساب (رقم عادي)', 'url' => 'openwa_bot', 'icon' => 'fab fa-whatsapp'),
            array('label' => 'Telegram', 'ar' => 'تليجرام', 'url' => 'telegram_bot', 'icon' => 'fab fa-telegram'),
            array('label' => 'Website chat', 'ar' => 'شات الموقع', 'url' => 'webchat', 'icon' => 'fas fa-comment-dots'),
        );
    }

    /**
     * Every channel the tenant has connected, with a stable selection key. OpenWA (personal
     * WhatsApp) and WhatsApp Cloud both report channel 'wa' with independent numeric ids, so
     * OpenWA rows get their own 'openwa:' key to keep the two apart.
     */
    private function targets_all()
    {
        $out = array();
        foreach (ai_agent_connected_channels($this->uid) as $c) {
            $kind = ($c['channel'] === 'wa' && empty($c['assignable'])) ? 'openwa' : $c['channel'];
            $c['kind'] = $kind;
            $c['key'] = $kind . ':' . $c['target'];
            $out[$c['key']] = $c;
        }
        return $out;
    }

    /**
     * The pages/channels the owner picked for THIS business in step 2, re-checked against
     * what is connected right now (a disconnected page drops out; a crafted key from another
     * tenant never matches). Everything after step 2 acts on these only — never on the whole
     * account, which may hold many unrelated businesses.
     */
    private function selected_targets($st)
    {
        $all = $this->targets_all();
        $out = array();
        foreach ((array) ($st['data']['targets'] ?? array()) as $k) {
            if (isset($all[$k])) $out[$k] = $all[$k];
        }
        return $out;
    }

    /** The page id the AI reply pipeline receives for this target (what ai_faq.page_id must hold). */
    private function runtime_page_id($c)
    {
        switch ($c['kind']) {
            case 'fb':
                $r = $this->db->select('page_id')->from('facebook_rx_fb_page_info')
                    ->where('id', (int) $c['target'])->where('user_id', $this->uid)->get()->row_array();
                return $r ? (string) $r['page_id'] : null;
            case 'openwa':
                $r = $this->db->select('session_id')->from('openwa_accounts')
                    ->where('id', (int) $c['target'])->where('user_id', $this->uid)->get()->row_array();
                return $r ? (string) $r['session_id'] : null;
            default:   // wa (Cloud) and tg pass their account id, web passes its widget_key
                return (string) $c['target'];
        }
    }

    /**
     * The chosen pages as price-list scopes, each with what it has today:
     *   has_own   the page has its own list (on or off) — step 5 never overwrites it
     *   resolves  the AI gets an ACTIVE list on this page (its own, else the default)
     * A page only counts if pricing's own IDOR gate agrees it is this tenant's connection.
     */
    private function pricing_pages($targets)
    {
        $owned = array();
        foreach (pricing_connected_targets($this->uid) as $t) $owned[$t['scope']] = true;
        $out = array();
        foreach ($targets as $k => $c) {
            $scope = industry_template_pricing_scope($k);
            if ($scope === null || !isset($owned[$scope[0] . ':' . $scope[1]])) continue;
            $own = pricing_get_row($this->uid, $scope, false);
            $out[$k] = array(
                'key' => $k, 'label' => (string) $c['label'], 'note' => (string) ($c['note'] ?? ''),
                'scope' => $scope, 'scope_key' => $scope[0] . ':' . $scope[1],
                'has_own' => !empty($own),
                'own_active' => !empty($own) && (string) $own['status'] === '1',
                'resolves' => pricing_get_config($this->uid, $scope) !== null,
            );
        }
        return $out;
    }

    /** How many price lists the account has, in any scope, on or off. */
    private function pricing_lists_total()
    {
        if (!$this->db->table_exists('pricing_config')) return 0;
        return (int) $this->db->from('pricing_config')->where('user_id', $this->uid)->count_all_results();
    }

    /** The one profile this wizard owns for the tenant (tracked in data_json). */
    private function wizard_profile($st)
    {
        $pid = (int) ($st['data']['profile_id'] ?? 0);
        if ($pid > 0) {
            $p = $this->db->from('ai_agent_profiles')->where('id', $pid)->where('user_id', $this->uid)->get()->row_array();
            if (!empty($p)) return $p;
        }
        return null;
    }

    private function ai_ready()
    {
        $acc = $this->db->from('open_ai_config')->where('user_id', $this->uid)->limit(1)->get()->row_array();
        if (empty($acc)) return false;
        $this->load->helper('ai_knowledge');
        return ai_openai_key($this->uid) !== '';
    }

    /** Ids of the selected targets that carry working hours, per table. */
    private function hours_ids($targets)
    {
        $ids = array('facebook_rx_fb_page_info' => array(), 'openwa_accounts' => array());
        foreach ($targets as $c) {
            if ($c['kind'] === 'fb') $ids['facebook_rx_fb_page_info'][] = (int) $c['target'];
            elseif ($c['kind'] === 'openwa') $ids['openwa_accounts'][] = (int) $c['target'];
        }
        return $ids;
    }

    private function current_hours($targets = array())
    {
        $out = array('rows' => 0, 'enabled' => '0', 'start' => '09:00:00', 'end' => '22:00:00', 'days' => '1,2,3,4,5,6,7');
        $first = null;
        foreach ($this->hours_ids($targets) as $t => $ids) {
            if (empty($ids) || !$this->db->table_exists($t)) continue;
            foreach ($this->db->from($t)->where('user_id', $this->uid)->where_in('id', $ids)->get()->result_array() as $r) {
                $out['rows']++;
                if ($first === null) $first = $r;
            }
        }
        if ($first !== null) {
            $out['enabled'] = (string) ($first['hours_enabled'] ?? '0');
            $out['start'] = (string) ($first['hours_start'] ?? '09:00:00');
            $out['end'] = (string) ($first['hours_end'] ?? '22:00:00');
            $out['days'] = (string) ($first['hours_days'] ?? '1,2,3,4,5,6,7');
        }
        return $out;
    }

    /* ------------------------------------------------------------------ steps */

    /** Step 1 — business type. Writes only onboarding_state. */
    public function save_business()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $key = (string) $this->input->post('template_key', true);
        $tpl = industry_template_load($key);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }
        $errors = industry_template_validate($tpl);
        if (!empty($errors)) { $this->jout(array('status' => '0', 'message' => 'This business type is not configured correctly.', 'errors' => $errors)); return; }

        $biz = trim(strip_tags((string) $this->input->post('business_name', true)));
        $this->save_state(array('business_name' => $biz), 1, $tpl['key']);
        $this->jout(array('status' => '1', 'template_key' => $tpl['key']));
    }

    /** Step 2 — channels. Writes NOTHING but the wizard's own progress. */
    public function save_channels()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $all = $this->targets_all();
        $picked = array();
        foreach ((array) $this->input->post('targets', true) as $k) {
            $k = (string) $k;
            if (isset($all[$k])) $picked[] = $k;   // only this tenant's own, currently connected targets
        }
        $picked = array_values(array_unique($picked));
        if (empty($picked)) {
            $this->jout(array('status' => '0', 'message' => 'Choose at least one page or channel for this business.'));
            return;
        }
        $this->save_state(array('targets' => $picked, 'channels_seen_at' => date('Y-m-d H:i:s'), 'channels_count' => count($picked)), 2);
        $this->jout(array('status' => '1', 'selected' => count($picked)));
    }

    /**
     * Step 3 — AI Employee. Creates or updates ONE ai_agent_profiles row for the tenant
     * with the template persona compiled into agent_name / instruction_to_ai /
     * sales_system_prompt, then assigns it to any connected channel that has no assignment
     * yet. Existing assignments are left alone; nothing is deleted.
     */
    public function save_agent()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }
        if (empty($this->selected_targets($st))) { $this->jout(array('status' => '0', 'message' => 'Choose the pages for this business first (step 2).')); return; }

        $overrides = array(
            'agent_name'    => (string) $this->input->post('agent_name', true),
            'role'          => (string) $this->input->post('role', true),
            'goal'          => (string) $this->input->post('goal', true),
            'business_name' => (string) ($this->input->post('business_name', true) ?: ($st['data']['business_name'] ?? '')),
            'extra'         => (string) $this->input->post('extra', true),
        );
        $compiled = industry_template_compile_prompt($tpl, $overrides);
        if (trim($compiled['agent_name']) === '') { $this->jout(array('status' => '0', 'message' => 'Give your AI employee a name.')); return; }

        $fields = array(
            'name'                 => mb_substr('Setup Guide — ' . industry_template_text($tpl['display_name'], 'en'), 0, 100),
            'agent_name'           => $compiled['agent_name'],
            'instruction_to_ai'    => $compiled['instruction_to_ai'],
            'sales_mode_enabled'   => '1',
            'sales_system_prompt'  => $compiled['sales_system_prompt'],
            'max_history_messages' => 6,
            'temperature'          => 0.4,
            'memory_ttl_hours'     => 24,
            'auto_language'        => '1',
            // the price tool also serves a products & services list saved on step 5
            'ai_tools_enabled'     => (!empty($tpl['pricing_supported']) || ($st['data']['pricing_mode'] ?? '') === 'catalog') ? '1' : '0',
            'status'               => '1',
        );

        $existing = $this->wizard_profile($st);
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->where('user_id', $this->uid)->update('ai_agent_profiles', $fields);
            $pid = (int) $existing['id'];
        } else {
            $fields['user_id'] = $this->uid;
            $fields['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert('ai_agent_profiles', $fields);
            $pid = (int) $this->db->insert_id();
        }

        $assigned = 0; $kept = array();
        foreach ($this->selected_targets($st) as $c) {
            if (empty($c['assignable'])) continue;
            $has = $this->db->from('ai_agent_assignments')->where('user_id', $this->uid)
                ->where('channel_type', $c['channel'])->where('target_id', (string) $c['target'])->count_all_results();
            if ($has > 0) { $kept[] = $c['label']; continue; }   // owner's own choice wins; never overwritten, never deleted
            $this->db->insert('ai_agent_assignments', array(
                'user_id' => $this->uid, 'profile_id' => $pid,
                'channel_type' => $c['channel'], 'target_id' => (string) $c['target'],
            ));
            $assigned++;
        }

        $this->save_state(array('profile_id' => $pid, 'agent_overrides' => $overrides), 3);
        $this->jout(array('status' => '1', 'profile_id' => $pid, 'assigned' => $assigned, 'kept_existing' => $kept));
    }

    /**
     * Step 4 — Knowledge. Inserts the template's starter FAQ into ai_faq, one row per
     * template key, keyed 'tpl:<template>:<faq key>' per tenant. Re-running UPDATES those
     * rows in place (never a second copy) and leaves every hand-written FAQ untouched.
     */
    public function save_knowledge()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }
        if (empty($this->selected_targets($st))) { $this->jout(array('status' => '0', 'message' => 'Choose the pages for this business first (step 2).')); return; }

        $answers = $this->input->post('faq', true);      // faq[<key>] = answer text (optional)
        if (!is_array($answers)) $answers = array();

        // Starter answers are scoped to each page picked in step 2 (ai_faq.page_id = the id
        // the reply pipeline sees), never account-wide: one account can hold many unrelated
        // businesses. A question the owner has not answered is saved switched OFF, so a
        // template placeholder can never reach a customer.
        $inserted = 0; $updated = 0; $now = date('Y-m-d H:i:s');
        foreach ($this->selected_targets($st) as $c) {
            $pid_rt = $this->runtime_page_id($c);
            if ($pid_rt === null || $pid_rt === '') continue;
            $sfx = '@' . substr(md5($c['key']), 0, 8);

            // this page's existing starter rows, keyed by the base source_key
            $existing = array();
            foreach ($this->db->from('ai_faq')->where('user_id', $this->uid)
                ->like('source_key', $sfx, 'before')->get()->result_array() as $r) {
                $existing[substr((string) $r['source_key'], 0, -strlen($sfx))] = $r;
            }
            foreach (industry_template_faq_plan($tpl, $existing, $answers) as $p) {
                $typed = isset($answers[$p['faq_key']]) && trim((string) $answers[$p['faq_key']]) !== '';
                if ($p['op'] === 'insert') {
                    $this->db->insert('ai_faq', array(
                        'user_id' => $this->uid, 'page_id' => $pid_rt, 'source_key' => $p['source_key'] . $sfx,
                        'question' => $p['question'], 'answer' => $p['answer'],
                        'status' => $typed ? '1' : '0', 'created_at' => $now,
                    ));
                    $inserted++;
                } else {
                    $set = array('question' => $p['question']);
                    // answer === null means "the owner typed nothing this time" — keep their text.
                    if ($p['answer'] !== null) { $set['answer'] = $p['answer']; $set['status'] = '1'; }
                    $this->db->where('id', (int) $p['id'])->where('user_id', $this->uid)->update('ai_faq', $set);
                    $updated++;
                }
            }
        }

        $this->save_state(array('faq_applied_at' => $now), 4);
        $this->jout(array('status' => '1', 'inserted' => $inserted, 'updated' => $updated));
    }

    /**
     * Step 5 — Products / Pricing. Writes through pricing_save_config() (same validation,
     * same audited history as the Products & Pricing screen), and ONLY onto the pages chosen
     * in step 2: one page-scoped list per chosen page that has no list of its own yet. A
     * page that already has a list keeps it untouched and is reported back (kept_existing).
     * The account-wide default list is written only when the account has no price list at
     * all AND the owner ticked "use for all my pages".
     *   hotel (calculator verticals): the rooms / day-use skeleton.
     *   other verticals: a few products & services typed right here (name, price, unit),
     *     saved as a catalog; or skip, which records that prices stay as answers and writes
     *     no pricing_config at all.
     */
    public function save_pricing()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }
        $targets = $this->selected_targets($st);
        if (empty($targets)) { $this->jout(array('status' => '0', 'message' => 'Choose the pages for this business first (step 2).')); return; }

        if (empty($tpl['pricing_supported'])) {
            $cfg = $this->input->post('skip', true) === '1' ? null
                : industry_template_catalog_config($this->input->post('items', true), (string) $this->input->post('currency', true));
            if ($cfg === null) {
                $this->save_state(array('pricing_mode' => 'faq_only', 'pricing_done_at' => date('Y-m-d H:i:s')), 5);
                $this->jout(array('status' => '1', 'mode' => 'faq_only', 'message' => industry_template_text($tpl['pricing_note'], 'ar')));
                return;
            }
            $mode = 'catalog';
            $summary = 'Setup Guide: products & services';
        } else {
            $cfg = json_decode((string) $this->input->post('config_json'), true);
            if (!is_array($cfg)) $cfg = $tpl['pricing_config'];
            $mode = 'calculator';
            $summary = 'Setup Guide: starter price list';
        }

        // Validate once, before touching any page, so a bad row never leaves half the pages written.
        $errors = pricing_validate_config($cfg);
        if (!empty($errors)) { $this->jout(array('status' => '0', 'message' => 'Please fix these first:', 'errors' => $errors)); return; }

        $pages = $this->pricing_pages($targets);
        $scopes = array(); $has = array();
        foreach ($pages as $k => $p) { $scopes[$k] = $p['scope']; if ($p['has_own']) $has[$k] = true; }
        $plan = industry_template_pricing_plan($scopes, $has, $this->input->post('use_for_all', true) === '1', $this->pricing_lists_total());
        if ($plan['error'] !== null) { $this->jout(array('status' => '0', 'message' => $plan['error'])); return; }

        $by = (int) $this->session->userdata('user_id');
        $saved = array(); $failed = array();
        if ($plan['default']) {
            list($ok, $errs) = pricing_save_config($this->uid, $cfg, $by, $summary, null);
            if (!$ok) { $this->jout(array('status' => '0', 'errors' => $errs)); return; }
            $saved[] = 'All pages';
        }
        foreach ($plan['write'] as $k => $scope) {
            // pricing_save_config() audits with the page's channel_type + target_id
            list($ok, $errs) = pricing_save_config($this->uid, $cfg, $by, $summary, $scope);
            if ($ok) $saved[] = $pages[$k]['label'];
            else $failed[] = htmlspecialchars($pages[$k]['label'], ENT_QUOTES, 'UTF-8') . ': ' . implode(' ', $errs);
        }
        if (!empty($failed)) {
            $this->jout(array('status' => '0', 'message' => 'Some pages could not be saved.', 'errors' => $failed, 'saved_pages' => $saved));
            return;
        }
        $kept = array();
        foreach ($plan['kept'] as $k) $kept[] = $pages[$k]['label'];

        // A products & services list only reaches customers through the price tool, so the
        // wizard's own AI employee gets tools on (never another profile the owner made).
        if ($mode === 'catalog') {
            $profile = $this->wizard_profile($st);
            if ($profile !== null) {
                $this->db->where('id', (int) $profile['id'])->where('user_id', $this->uid)->update('ai_agent_profiles', array('ai_tools_enabled' => '1'));
            }
        }

        $this->save_state(array('pricing_mode' => $mode, 'pricing_done_at' => date('Y-m-d H:i:s')), 5);
        $this->jout(array('status' => '1', 'mode' => $mode, 'default' => $plan['default'],
            'saved' => count($saved), 'saved_pages' => $saved, 'kept_existing' => $kept));
    }

    /**
     * Step 6 — Lead pipeline. crm_ensure_pipeline() creates the tenant's default pipeline
     * exactly as the CRM does; this step then ADDS any template stage that is missing, by
     * name. Existing stages keep their id, their position and their deals. Nothing is
     * renamed away or deleted, so re-running is a no-op once the stages exist.
     */
    public function save_pipeline()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }

        $pid = crm_ensure_pipeline($this->uid);
        if (!$pid) { $this->jout(array('status' => '0', 'message' => 'Could not open your pipeline.')); return; }

        $existing = array();
        $max_pos = 0;
        foreach ($this->db->from('crm_stages')->where('pipeline_id', $pid)->get()->result_array() as $s) {
            $existing[mb_strtolower(trim($s['name']))] = $s;
            $max_pos = max($max_pos, (int) $s['position']);
        }

        $added = 0;
        foreach ($tpl['pipeline']['stages'] as $s) {
            $k = mb_strtolower(trim($s['name']));
            if (isset($existing[$k])) continue;
            $max_pos++;
            $this->db->insert('crm_stages', array(
                'pipeline_id' => $pid, 'name' => $s['name'], 'color' => $s['color'],
                'stage_type' => $s['stage_type'], 'position' => $max_pos,
            ));
            $added++;
        }

        $this->save_state(array('pipeline_id' => (int) $pid, 'pipeline_done_at' => date('Y-m-d H:i:s')), 6);
        $this->jout(array('status' => '1', 'pipeline_id' => (int) $pid, 'added' => $added));
    }

    /**
     * Step 7 — Working mode. Writes the same hours_* columns, in the same format and
     * through the same normaliser, that the Messenger / WhatsApp settings pages use.
     * hours_enabled '1' = staff shift window, inside which the AI stays silent.
     */
    public function save_hours()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }
        if (empty($this->selected_targets($st))) { $this->jout(array('status' => '0', 'message' => 'Choose the pages for this business first (step 2).')); return; }
        $wm = $tpl['working_mode'];

        $mode = $this->input->post('mode', true) === 'staff_hours_silent' ? 'staff_hours_silent' : 'always_on';
        $enabled = $mode === 'staff_hours_silent' ? '1' : '0';
        $start = working_hours_normalize_time($this->input->post('hours_start', true), $wm['hours_start']);
        $end   = working_hours_normalize_time($this->input->post('hours_end', true), $wm['hours_end']);

        $raw = $this->input->post('hours_days');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $days = array();
        foreach ($parts as $d) {
            $n = (int) trim((string) $d);
            if ($n >= 1 && $n <= 7) $days[] = (string) $n;
        }
        $days = array_values(array_unique($days));
        sort($days, SORT_NUMERIC);
        if (empty($days)) {
            // Same rule as Messenger_bot's settings save: an empty/invalid day list keeps
            // what is already stored rather than silently widening the window. Only a brand
            // new setup falls back to the template's recommendation.
            $cur = $this->current_hours($this->selected_targets($st));
            $hours_days = $cur['rows'] > 0 ? $cur['days'] : (string) $wm['hours_days'];
        } else {
            $hours_days = implode(',', $days);
        }

        $set = array('hours_enabled' => $enabled, 'hours_start' => $start, 'hours_end' => $end, 'hours_days' => $hours_days);
        // Only the pages picked for this business — never every page on the account.
        $touched = 0;
        foreach ($this->hours_ids($this->selected_targets($st)) as $t => $ids) {
            if (empty($ids) || !$this->db->table_exists($t)) continue;
            $this->db->where('user_id', $this->uid)->where_in('id', $ids)->update($t, $set);
            $touched += (int) $this->db->affected_rows();
        }

        $this->save_state(array('working_mode' => $mode, 'hours' => $set, 'hours_done_at' => date('Y-m-d H:i:s')), 7);
        $this->jout(array('status' => '1', 'mode' => $mode, 'channels_updated' => $touched));
    }

    /**
     * Step 8 — Test conversation. Runs ai_agent_preview_reply(), the same function
     * /ai_agents/preview runs. With no credentials or no key it says so plainly; it NEVER
     * shows a made-up reply.
     */
    public function test_reply()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $profile = $this->wizard_profile($st);
        if ($profile === null) { $this->jout(array('status' => '0', 'message' => 'Create your AI employee first (step 3).')); return; }

        $res = ai_agent_preview_reply($this->uid, (string) $this->input->post('message', true), array(
            'agent_name'          => $profile['agent_name'],
            'instruction_to_ai'   => $profile['instruction_to_ai'],
            'sales_system_prompt' => $profile['sales_system_prompt'],
        ));
        if (($res['status'] ?? '0') === '1') $this->save_state(array('tested_at' => date('Y-m-d H:i:s')), 8);
        $this->jout($res);
    }

    /** Step 9 — Go live. Records completion. The checklist is computed, never stored. */
    public function go_live()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }

        $checklist = $this->checklist($st, $tpl);
        $blocking = array();
        foreach ($checklist as $c) if (!empty($c['required']) && empty($c['done'])) $blocking[] = $c['label_en'];
        if (!empty($blocking)) { $this->jout(array('status' => '0', 'message' => 'Still missing: ' . implode(', ', $blocking), 'missing' => $blocking)); return; }

        $this->db->where('user_id', $this->uid)->update('onboarding_state', array(
            'current_step' => self::STEPS,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ));
        $this->jout(array('status' => '1'));
    }

    /**
     * Step 10 — Extras (optional, brief §33 / Task 23). Fully skippable: the wizard never
     * calls this and never redirects here on its own. Every row this creates is created
     * DISABLED — sequences and automation rules as status 'draft' (Sequences.php /
     * Automations.php only ever act on 'active'), the appointment service as status '0'
     * (Appointment_booking only ever books against '1'). The owner is the ONLY path to "on":
     * this step never flips a status to active/1, no matter what is checked.
     *
     * Idempotent: which template sequences/rules were already created here is remembered in
     * onboarding_state.data_json (extras_applied: source_key => row id, the same "wizard's
     * own bookkeeping" pattern save_state already uses for profile_id/faq_applied_at) — an
     * already-applied item is skipped even if its checkbox is re-submitted checked, and the
     * appointment service is judged straight from ab_services (any row for this tenant), not
     * from bookkeeping, so it can never be created twice either.
     */
    public function save_extras()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }
        if (empty($this->selected_targets($st))) { $this->jout(array('status' => '0', 'message' => 'Choose the pages for this business first (step 2).')); return; }

        $applied = (array) ($st['data']['extras_applied'] ?? array());
        $checked_seq = array_map('strval', (array) $this->input->post('sequences', true));
        $checked_auto = array_map('strval', (array) $this->input->post('automation_rules', true));
        $checked_appt = (string) $this->input->post('appointment', true) === '1';
        $now = date('Y-m-d H:i:s');

        // -- sequences --------------------------------------------------------------
        $existing_seq = array();
        foreach ($applied as $sk => $rid) if (strpos($sk, 'seq:') === 0) $existing_seq[substr($sk, 4)] = array('id' => $rid);
        $seq_created = 0;
        foreach (industry_template_sequences_plan($tpl, $existing_seq) as $p) {
            if ($p['op'] !== 'insert' || !in_array($p['source_key'], $checked_seq, true)) continue;
            $this->db->insert('sequences', array(
                'user_id' => $this->uid, 'name' => mb_substr($p['name'], 0, 190),
                'trigger_event' => 'manual', 'enroll_conditions_json' => null,
                // ALWAYS 'draft': a template extra never runs until the owner publishes it.
                'status' => $p['status'], 'created_at' => $now, 'updated_at' => $now,
            ));
            $sid = (int) $this->db->insert_id();
            foreach ($p['steps'] as $pos => $step) {
                $this->db->insert('sequence_steps', array(
                    'sequence_id' => $sid, 'position' => $pos, 'delay_seconds' => $step['delay_seconds'],
                    'action_type' => $step['action_type'], 'action_params_json' => json_encode($step['action_params'], JSON_UNESCAPED_UNICODE),
                ));
            }
            $applied['seq:' . $p['source_key']] = $sid;
            $seq_created++;
        }

        // -- automation rules ---------------------------------------------------------
        $existing_auto = array();
        foreach ($applied as $sk => $rid) if (strpos($sk, 'auto:') === 0) $existing_auto[substr($sk, 5)] = array('id' => $rid);
        $auto_created = 0;
        foreach (industry_template_automation_plan($tpl, $existing_auto) as $p) {
            if ($p['op'] !== 'insert' || !in_array($p['source_key'], $checked_auto, true)) continue;
            $this->db->insert('automation_rules', array(
                'user_id' => $this->uid, 'name' => mb_substr($p['name'], 0, 190),
                'trigger_event' => $p['trigger_event'], 'conditions_json' => $p['conditions_json'], 'actions_json' => $p['actions_json'],
                // ALWAYS 'draft': a template extra never fires until the owner publishes it.
                'status' => $p['status'], 'created_by' => (int) $this->session->userdata('user_id'),
                'created_at' => $now, 'updated_at' => $now,
            ));
            $applied['auto:' . $p['source_key']] = (int) $this->db->insert_id();
            $auto_created++;
        }

        // -- appointment default service ------------------------------------------------
        // The template ships appointments.enabled = false (never auto-applied); ticking
        // this box on this step IS the owner's explicit opt-in the plan function requires.
        $has_service = $this->db->from('ab_services')->where('user_id', $this->uid)->count_all_results() > 0;
        $ap_tpl = $tpl;
        if (is_array($ap_tpl['appointments'] ?? null)) $ap_tpl['appointments']['enabled'] = $checked_appt;
        $ap_plan = industry_template_appointments_plan($ap_tpl, $has_service);
        $appt_created = false;
        if ($ap_plan['op'] === 'insert') {
            $this->db->insert('ab_services', array(
                'user_id' => $this->uid, 'name' => mb_substr($ap_plan['name'], 0, 120),
                'duration_min' => (int) $ap_plan['duration_min'], 'price' => 0, 'currency' => 'USD',
                // ALWAYS '0' (not bookable): matches ab_services.status semantics.
                'status' => $ap_plan['status'], 'created_at' => $now,
            ));
            $appt_created = true;
        }

        $this->save_state(array('extras_applied' => $applied, 'extras_applied_at' => $now), 10);
        $this->jout(array(
            'status' => '1', 'sequences_created' => $seq_created, 'automation_rules_created' => $auto_created,
            'appointment_created' => $appt_created,
        ));
    }

    /**
     * Data for step 10's view: every template extra, with whether it is already applied
     * (so the checkbox shows checked+disabled) — read the same way as everything else on
     * this step, from the wizard's own bookkeeping for sequences/rules (they have no
     * source_key column of their own) and live from ab_services for the appointment default.
     */
    private function extras_state($st, $tpl)
    {
        $applied = (array) ($st['data']['extras_applied'] ?? array());
        $existing_seq = array();
        foreach ($applied as $sk => $rid) if (strpos($sk, 'seq:') === 0) $existing_seq[substr($sk, 4)] = array('id' => $rid);
        $existing_auto = array();
        foreach ($applied as $sk => $rid) if (strpos($sk, 'auto:') === 0) $existing_auto[substr($sk, 5)] = array('id' => $rid);

        $has_service = $this->db->from('ab_services')->where('user_id', $this->uid)->count_all_results() > 0;
        $ap_tpl = $tpl;
        if (is_array($ap_tpl['appointments'] ?? null)) $ap_tpl['appointments']['enabled'] = true;   // preview only: "what WOULD be offered if ticked"

        return array(
            'sequences' => industry_template_sequences_plan($tpl, $existing_seq),
            'automation_rules' => industry_template_automation_plan($tpl, $existing_auto),
            'media' => industry_template_media_plan($tpl),
            'appointments' => industry_template_appointments_plan($ap_tpl, $has_service),
            'has_service' => $has_service,
        );
    }

    /* ------------------------------------------------------------- checklist */

    /**
     * What is configured and what is missing — read live from the feature tables, not from
     * the wizard's own bookkeeping, so a checklist tick always means the feature is really
     * set up.
     */
    private function checklist($st, $tpl)
    {
        $out = array();
        $out[] = array('key' => 'business', 'label_en' => 'Business type chosen', 'label_ar' => 'اخترت نوع النشاط',
            'done' => $tpl !== null, 'required' => true,
            'detail' => $tpl ? industry_template_text($tpl['display_name'], 'ar') : '');

        $channels = $this->selected_targets($st);
        $out[] = array('key' => 'channels', 'label_en' => 'Pages chosen for this business', 'label_ar' => 'اخترت صفحات النشاط ده',
            'done' => count($channels) > 0, 'required' => true,
            'detail' => implode(', ', array_map(function ($c) { return $c['label']; }, $channels)), 'link' => 'onboarding/step/2');

        $profile = $this->wizard_profile($st);
        $out[] = array('key' => 'agent', 'label_en' => 'AI employee created', 'label_ar' => 'الموظف الذكي اتعمل',
            'done' => $profile !== null, 'required' => true,
            'detail' => $profile ? (string) $profile['agent_name'] : '', 'link' => 'onboarding/step/3');

        $assigned = $profile === null ? 0 : (int) $this->db->from('ai_agent_assignments')
            ->where('user_id', $this->uid)->where('profile_id', (int) $profile['id'])->count_all_results();
        $out[] = array('key' => 'assigned', 'label_en' => 'AI employee answering on a channel', 'label_ar' => 'الموظف الذكي بيرد على قناة',
            'done' => $assigned > 0, 'required' => false,
            'detail' => $assigned . ' channel(s)', 'link' => 'ai_agents');

        // Live answers the chosen pages will actually use: their own rows plus account-wide ones.
        $rt = array();
        foreach ($channels as $c) { $x = $this->runtime_page_id($c); if ($x !== null && $x !== '') $rt[] = $x; }
        $faq = 0;
        if (!empty($rt)) {
            $faq = (int) $this->db->from('ai_faq')->where('user_id', $this->uid)->where('status', '1')
                ->group_start()->where_in('page_id', $rt)->or_where('page_id', null)->or_where('page_id', '')->group_end()
                ->count_all_results();
        }
        $out[] = array('key' => 'knowledge', 'label_en' => 'At least one real answer written', 'label_ar' => 'فيه إجابة حقيقية واحدة على الأقل',
            'done' => $faq > 0, 'required' => true,
            'detail' => $faq . ' question(s)', 'link' => 'onboarding/step/4');

        // Prices: judged per chosen page — each must resolve to an ACTIVE list (its own, or
        // the default one it falls back to), exactly what the AI will use on that page.
        $pp = $this->pricing_pages($channels);
        $missing = array();
        foreach ($pp as $p) if (!$p['resolves']) $missing[] = $p['label'];
        $all_priced = !empty($pp) && empty($missing);
        $faq_only = empty($tpl['pricing_supported']) && ($st['data']['pricing_mode'] ?? '') === 'faq_only';
        if ($all_priced) {
            $detail = count($pp) . ' page(s) have a price list';
        } elseif (!empty($pp)) {
            $detail = 'No price list yet: ' . implode(', ', $missing);
        } else {
            $detail = '';
        }
        if ($faq_only && !$all_priced) {
            $out[] = array('key' => 'pricing', 'label_en' => 'Prices kept as answers', 'label_ar' => 'الأسعار متسجلة كإجابات',
                'done' => true, 'required' => false,
                'detail' => trim(industry_template_text($tpl['pricing_note'], 'ar') . ($detail !== '' ? ' — ' . $detail : '')),
                'link' => 'onboarding/step/5');
        } else {
            $out[] = array('key' => 'pricing', 'label_en' => 'Price list on every page', 'label_ar' => 'قايمة الأسعار على كل الصفحات',
                'done' => $all_priced, 'required' => false,
                'detail' => $detail, 'link' => 'onboarding/step/5', 'missing' => $missing);
        }

        $pl = $this->db->from('crm_pipelines')->where('user_id', $this->uid)->order_by('id', 'ASC')->limit(1)->get()->row_array();
        $stages = empty($pl) ? 0 : (int) $this->db->from('crm_stages')->where('pipeline_id', (int) $pl['id'])->count_all_results();
        $out[] = array('key' => 'pipeline', 'label_en' => 'Lead stages ready', 'label_ar' => 'مراحل العملاء جاهزة',
            'done' => $stages > 0, 'required' => false,
            'detail' => $stages . ' stage(s)', 'link' => 'crm');

        $out[] = array('key' => 'hours', 'label_en' => 'Working mode chosen', 'label_ar' => 'طريقة الشغل اتحددت',
            'done' => !empty($st['data']['hours_done_at']), 'required' => false,
            'detail' => (string) ($st['data']['working_mode'] ?? ''), 'link' => 'onboarding/step/7');

        $out[] = array('key' => 'tested', 'label_en' => 'Test conversation run', 'label_ar' => 'جرّبت محادثة',
            'done' => !empty($st['data']['tested_at']), 'required' => false,
            'detail' => $this->ai_ready() ? '' : 'AI key not set up yet', 'link' => 'onboarding/step/8');

        $out[] = array('key' => 'extras', 'label_en' => 'Optional extras reviewed', 'label_ar' => 'راجعت الإضافات الاختيارية',
            'done' => !empty($st['data']['extras_applied_at']), 'required' => false,
            'detail' => 'Sequences, automations, appointments — all optional', 'link' => 'onboarding/step/10');

        return $out;
    }

    private function progress($st, $tpl)
    {
        $done = 0;
        $flags = array(
            1 => $tpl !== null,
            2 => !empty($st['data']['targets']),
            3 => !empty($st['data']['profile_id']),
            4 => !empty($st['data']['faq_applied_at']),
            5 => !empty($st['data']['pricing_done_at']),
            6 => !empty($st['data']['pipeline_done_at']),
            7 => !empty($st['data']['hours_done_at']),
            8 => !empty($st['data']['tested_at']),
            9 => !empty($st['completed_at']),
            10 => !empty($st['data']['extras_applied_at']),
        );
        foreach ($flags as $ok) if ($ok) $done++;
        return array('done' => $done, 'total' => self::STEPS, 'flags' => $flags);
    }
}
