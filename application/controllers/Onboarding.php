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
 *   Prices       -> pricing_save_config()  (validated + audited, same as /pricing)
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
    const STEPS = 9;

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
                $data['channels'] = ai_agent_connected_channels($this->uid);
                $data['connect_links'] = $this->connect_links();
                break;
            case 3:
                $data['profile'] = $this->wizard_profile($st);
                break;
            case 4:
                $data['faq_rows'] = $this->db->from('ai_faq')->where('user_id', $this->uid)
                    ->like('source_key', 'tpl:', 'after')->order_by('id', 'ASC')->get()->result_array();
                break;
            case 5:
                $row = pricing_get_row($this->uid);   // the account-wide default list (per-page lists live on /pricing)
                $saved = !empty($row['config_json']) ? json_decode($row['config_json'], true) : null;
                $data['cfg'] = is_array($saved) ? $saved : ($tpl['pricing_config'] ?? null);
                $data['cfg_saved'] = is_array($saved);
                break;
            case 6:
                $pid = $this->db->from('crm_pipelines')->where('user_id', $this->uid)->order_by('id', 'ASC')->limit(1)->get()->row_array();
                $data['pipeline'] = $pid;
                $data['stages'] = empty($pid) ? array()
                    : $this->db->from('crm_stages')->where('pipeline_id', $pid['id'])->order_by('position', 'ASC')->get()->result_array();
                break;
            case 7:
                $data['hours'] = $this->current_hours();
                break;
            case 8:
                $data['profile'] = $this->wizard_profile($st);
                $data['ai_ready'] = $this->ai_ready();
                break;
            case 9:
                $data['checklist'] = $this->checklist($st, $tpl);
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

    private function current_hours()
    {
        $out = array('rows' => 0, 'enabled' => '0', 'start' => '09:00:00', 'end' => '22:00:00', 'days' => '1,2,3,4,5,6,7');
        $first = null;
        foreach (array('facebook_rx_fb_page_info', 'openwa_accounts') as $t) {
            if (!$this->db->table_exists($t)) continue;
            foreach ($this->db->from($t)->where('user_id', $this->uid)->get()->result_array() as $r) {
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
        $ch = ai_agent_connected_channels($this->uid);
        $this->save_state(array('channels_seen_at' => date('Y-m-d H:i:s'), 'channels_count' => count($ch)), 2);
        $this->jout(array('status' => '1', 'connected' => count($ch)));
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
            'ai_tools_enabled'     => !empty($tpl['pricing_supported']) ? '1' : '0',
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

        $assigned = 0;
        foreach (ai_agent_connected_channels($this->uid) as $c) {
            if (empty($c['assignable'])) continue;
            $has = $this->db->from('ai_agent_assignments')->where('user_id', $this->uid)
                ->where('channel_type', $c['channel'])->where('target_id', (string) $c['target'])->count_all_results();
            if ($has > 0) continue;   // owner's own choice wins; never overwritten, never deleted
            $this->db->insert('ai_agent_assignments', array(
                'user_id' => $this->uid, 'profile_id' => $pid,
                'channel_type' => $c['channel'], 'target_id' => (string) $c['target'],
            ));
            $assigned++;
        }

        $this->save_state(array('profile_id' => $pid, 'agent_overrides' => $overrides), 3);
        $this->jout(array('status' => '1', 'profile_id' => $pid, 'assigned' => $assigned));
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

        $answers = $this->input->post('faq', true);      // faq[<key>] = answer text (optional)
        if (!is_array($answers)) $answers = array();

        // Existing starter rows for THIS tenant, keyed by source_key. Hand-written FAQ rows
        // have source_key NULL and are never in this map, so the wizard cannot touch them.
        $existing = array();
        foreach ($this->db->from('ai_faq')->where('user_id', $this->uid)->where('source_key IS NOT NULL', null, false)->get()->result_array() as $r) {
            $existing[(string) $r['source_key']] = $r;
        }

        $inserted = 0; $updated = 0; $now = date('Y-m-d H:i:s');
        foreach (industry_template_faq_plan($tpl, $existing, $answers) as $p) {
            if ($p['op'] === 'insert') {
                $this->db->insert('ai_faq', array(
                    'user_id' => $this->uid, 'page_id' => null, 'source_key' => $p['source_key'],
                    'question' => $p['question'], 'answer' => $p['answer'], 'status' => '1', 'created_at' => $now,
                ));
                $inserted++;
            } else {
                $set = array('question' => $p['question']);
                // answer === null means "the owner typed nothing this time" — keep their text.
                if ($p['answer'] !== null) $set['answer'] = $p['answer'];
                $this->db->where('id', (int) $p['id'])->where('user_id', $this->uid)->update('ai_faq', $set);
                $updated++;
            }
        }

        $this->save_state(array('faq_applied_at' => $now), 4);
        $this->jout(array('status' => '1', 'inserted' => $inserted, 'updated' => $updated));
    }

    /**
     * Step 5 — Products / Pricing. Only templates whose vertical the price calculator
     * actually supports (accommodation + day-use = hotel today) save a pricing_config, and
     * they save it through pricing_save_config(): same validation, same audit history as
     * the Products & Pricing screen. Everything else records that prices live in FAQ /
     * Knowledge and writes no pricing_config at all.
     */
    public function save_pricing()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        $st = $this->state();
        $tpl = $this->template_of($st);
        if ($tpl === null) { $this->jout(array('status' => '0', 'message' => 'Pick a business type first.')); return; }

        if (empty($tpl['pricing_supported'])) {
            $this->save_state(array('pricing_mode' => 'faq_only', 'pricing_done_at' => date('Y-m-d H:i:s')), 5);
            $this->jout(array('status' => '1', 'mode' => 'faq_only', 'message' => industry_template_text($tpl['pricing_note'], 'ar')));
            return;
        }

        $cfg = json_decode((string) $this->input->post('config_json'), true);
        if (!is_array($cfg)) $cfg = $tpl['pricing_config'];
        list($ok, $errors) = pricing_save_config($this->uid, $cfg, (int) $this->session->userdata('user_id'), 'Setup Guide: starter price list');
        if (!$ok) { $this->jout(array('status' => '0', 'errors' => $errors)); return; }

        $this->save_state(array('pricing_mode' => 'calculator', 'pricing_done_at' => date('Y-m-d H:i:s')), 5);
        $this->jout(array('status' => '1', 'mode' => 'calculator'));
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
            $cur = $this->current_hours();
            $hours_days = $cur['rows'] > 0 ? $cur['days'] : (string) $wm['hours_days'];
        } else {
            $hours_days = implode(',', $days);
        }

        $set = array('hours_enabled' => $enabled, 'hours_start' => $start, 'hours_end' => $end, 'hours_days' => $hours_days);
        $touched = 0;
        foreach (array('facebook_rx_fb_page_info', 'openwa_accounts') as $t) {
            if (!$this->db->table_exists($t)) continue;
            $n = $this->db->from($t)->where('user_id', $this->uid)->count_all_results();
            if ($n === 0) continue;
            $this->db->where('user_id', $this->uid)->update($t, $set);
            $touched += $n;
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

        $channels = ai_agent_connected_channels($this->uid);
        $out[] = array('key' => 'channels', 'label_en' => 'At least one channel connected', 'label_ar' => 'قناة واحدة على الأقل متوصّلة',
            'done' => count($channels) > 0, 'required' => true,
            'detail' => count($channels) . ' channel(s)', 'link' => 'onboarding/step/2');

        $profile = $this->wizard_profile($st);
        $out[] = array('key' => 'agent', 'label_en' => 'AI employee created', 'label_ar' => 'الموظف الذكي اتعمل',
            'done' => $profile !== null, 'required' => true,
            'detail' => $profile ? (string) $profile['agent_name'] : '', 'link' => 'onboarding/step/3');

        $assigned = $profile === null ? 0 : (int) $this->db->from('ai_agent_assignments')
            ->where('user_id', $this->uid)->where('profile_id', (int) $profile['id'])->count_all_results();
        $out[] = array('key' => 'assigned', 'label_en' => 'AI employee answering on a channel', 'label_ar' => 'الموظف الذكي بيرد على قناة',
            'done' => $assigned > 0, 'required' => false,
            'detail' => $assigned . ' channel(s)', 'link' => 'ai_agents');

        $faq = (int) $this->db->from('ai_faq')->where('user_id', $this->uid)->where('status', '1')->count_all_results();
        $out[] = array('key' => 'knowledge', 'label_en' => 'Starter questions added', 'label_ar' => 'الأسئلة الشائعة اتضافت',
            'done' => $faq > 0, 'required' => true,
            'detail' => $faq . ' question(s)', 'link' => 'onboarding/step/4');

        if (!empty($tpl['pricing_supported'])) {
            $pc = $this->db->from('pricing_config')->where('user_id', $this->uid)->where('status', '1')->get()->row_array();
            $out[] = array('key' => 'pricing', 'label_en' => 'Price list saved', 'label_ar' => 'قايمة الأسعار اتحفظت',
                'done' => !empty($pc), 'required' => false,
                'detail' => empty($pc) ? '' : ('updated ' . $pc['updated_at']), 'link' => 'pricing');
        } else {
            $out[] = array('key' => 'pricing', 'label_en' => 'Prices kept as answers', 'label_ar' => 'الأسعار متسجلة كإجابات',
                'done' => ($st['data']['pricing_mode'] ?? '') === 'faq_only', 'required' => false,
                'detail' => industry_template_text($tpl['pricing_note'], 'ar'), 'link' => 'onboarding/step/5');
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

        return $out;
    }

    private function progress($st, $tpl)
    {
        $done = 0;
        $flags = array(
            1 => $tpl !== null,
            2 => !empty($st['data']['channels_seen_at']),
            3 => !empty($st['data']['profile_id']),
            4 => !empty($st['data']['faq_applied_at']),
            5 => !empty($st['data']['pricing_done_at']),
            6 => !empty($st['data']['pipeline_done_at']),
            7 => !empty($st['data']['hours_done_at']),
            8 => !empty($st['data']['tested_at']),
            9 => !empty($st['completed_at']),
        );
        foreach ($flags as $ok) if ($ok) $done++;
        return array('done' => $done, 'total' => self::STEPS, 'flags' => $flags);
    }
}
