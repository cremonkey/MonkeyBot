<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Phase 11 / brief §33 — industry templates are DATA, not code.
 *
 * A vertical is one JSON file in application/config/industry_templates/. Nothing in the
 * platform branches on the template key: the onboarding wizard reads the file and then
 * drives the EXISTING features through their existing save paths (pricing_save_config,
 * ai_agent_profiles, ai_faq, crm_ensure_pipeline, the hours_* columns). Adding a vertical
 * is adding a file — see docs/product/onboarding-and-templates.md.
 *
 * Deliberate limit: only `hotel` carries a pricing_config skeleton. The price calculator
 * (pricing_helper.php) understands accommodation nights and day-use tickets and nothing
 * else today, so the other verticals set pricing_supported=false and carry a
 * pricing_note explaining that prices belong in FAQ / Knowledge instead.
 */

if (!function_exists('industry_template_dir')) {
    function industry_template_dir()
    {
        return APPPATH . 'config/industry_templates/';
    }
}

if (!function_exists('industry_template_keys')) {
    /** @return string[] template keys, in display order */
    function industry_template_keys()
    {
        $order = array('hotel', 'clinic', 'ecommerce', 'real_estate');
        $found = array();
        foreach (glob(industry_template_dir() . '*.json') as $f) $found[] = basename($f, '.json');
        $out = array();
        foreach ($order as $k) if (in_array($k, $found, true)) $out[] = $k;
        foreach ($found as $k) if (!in_array($k, $out, true)) $out[] = $k;
        return $out;
    }
}

if (!function_exists('industry_template_load')) {
    /** @return array|null the decoded template, or null when the key is unknown/invalid */
    function industry_template_load($key)
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
        if ($key === '') return null;
        $path = industry_template_dir() . $key . '.json';
        if (!is_file($path)) return null;
        $tpl = json_decode(file_get_contents($path), true);
        if (!is_array($tpl)) return null;
        $tpl['key'] = isset($tpl['key']) ? $tpl['key'] : $key;
        return $tpl;
    }
}

if (!function_exists('industry_templates_all')) {
    /** @return array key => template */
    function industry_templates_all()
    {
        $out = array();
        foreach (industry_template_keys() as $k) {
            $t = industry_template_load($k);
            if ($t !== null) $out[$k] = $t;
        }
        return $out;
    }
}

if (!function_exists('industry_template_text')) {
    /** Bilingual field accessor: industry_template_text($tpl['display_name'], 'ar'). */
    function industry_template_text($node, $lang = 'en')
    {
        if (is_string($node)) return $node;
        if (!is_array($node)) return '';
        if (isset($node[$lang]) && $node[$lang] !== '') return (string) $node[$lang];
        if (isset($node['en'])) return (string) $node['en'];
        if (isset($node['ar'])) return (string) $node['ar'];
        return '';
    }
}

if (!function_exists('industry_template_validate')) {
    /**
     * Schema check for one template. Returns human-readable errors; empty = valid.
     * Used by tests/onboarding/run.php so a broken vertical file is caught before it
     * reaches a tenant, and by the wizard before it applies anything.
     */
    function industry_template_validate($tpl)
    {
        $e = array();
        if (!is_array($tpl)) return array('Template must be a JSON object.');

        $bi = function ($node, $label) use (&$e) {
            if (!is_array($node)) { $e[] = "$label: must be an object with en + ar."; return; }
            foreach (array('en', 'ar') as $l) {
                if (!isset($node[$l]) || trim((string) $node[$l]) === '') $e[] = "$label: missing $l text.";
            }
        };

        if (empty($tpl['key']) || !preg_match('/^[a-z0-9_]+$/', (string) $tpl['key'])) $e[] = 'key: must be lowercase a-z0-9_.';
        if (!isset($tpl['schema_version']) || (int) $tpl['schema_version'] < 1) $e[] = 'schema_version: must be >= 1.';
        $bi($tpl['display_name'] ?? null, 'display_name');
        $bi($tpl['tagline'] ?? null, 'tagline');

        // --- AI employee (§17) ---
        $ai = $tpl['ai_employee'] ?? null;
        if (!is_array($ai)) $e[] = 'ai_employee: missing.';
        else {
            $bi($ai['name_suggestion'] ?? null, 'ai_employee.name_suggestion');
            $bi($ai['role'] ?? null, 'ai_employee.role');
            $bi($ai['goal'] ?? null, 'ai_employee.goal');
            if (($ai['tone'] ?? '') !== 'Friendly Egyptian Arabic') $e[] = 'ai_employee.tone: must be "Friendly Egyptian Arabic".';
            $caps = $ai['capabilities'] ?? null;
            if (!is_array($caps) || count($caps) < 3) $e[] = 'ai_employee.capabilities: need at least 3.';
            else foreach (array_values($caps) as $i => $c) {
                if (empty($c['key'])) $e[] = 'ai_employee.capabilities[' . $i . ']: missing key.';
                $bi($c, 'ai_employee.capabilities[' . $i . ']');
            }
            $res = $ai['restrictions'] ?? null;
            if (!is_array($res)) $e[] = 'ai_employee.restrictions: missing.';
            else {
                if (!isset($res['max_discount_percent'])) $e[] = 'ai_employee.restrictions.max_discount_percent: missing.';
                elseif ((int) $res['max_discount_percent'] !== 0) $e[] = 'ai_employee.restrictions.max_discount_percent: must be 0 for a starter template.';
                $rules = $res['rules'] ?? null;
                if (!is_array($rules) || count($rules) < 3) $e[] = 'ai_employee.restrictions.rules: need at least 3.';
                else {
                    $keys = array();
                    foreach (array_values($rules) as $i => $r) {
                        if (empty($r['key'])) $e[] = 'ai_employee.restrictions.rules[' . $i . ']: missing key.';
                        else $keys[] = $r['key'];
                        $bi($r, 'ai_employee.restrictions.rules[' . $i . ']');
                    }
                    foreach (array('never_invent_prices', 'no_discount') as $must) {
                        if (!in_array($must, $keys, true)) $e[] = "ai_employee.restrictions.rules: must include \"$must\".";
                    }
                }
            }
        }

        // --- starter FAQ ---
        $faq = $tpl['starter_faq'] ?? null;
        if (!is_array($faq) || count($faq) < 6 || count($faq) > 10) {
            $e[] = 'starter_faq: need between 6 and 10 entries (got ' . (is_array($faq) ? count($faq) : 0) . ').';
        }
        if (is_array($faq)) {
            $seen = array();
            foreach (array_values($faq) as $i => $f) {
                $k = (string) ($f['key'] ?? '');
                if ($k === '' || !preg_match('/^[a-z0-9_]+$/', $k)) $e[] = "starter_faq[$i]: key must be lowercase a-z0-9_.";
                elseif (isset($seen[$k])) $e[] = "starter_faq[$i]: duplicate key \"$k\".";
                $seen[$k] = true;
                $bi($f['question'] ?? null, "starter_faq[$i].question");
                $bi($f['answer'] ?? null, "starter_faq[$i].answer");
                // every starter answer must be visibly a placeholder the owner replaces
                $ar = (string) ($f['answer']['ar'] ?? '');
                $en = (string) ($f['answer']['en'] ?? '');
                if (mb_stripos($ar, 'عدّلني') === false) $e[] = "starter_faq[$i].answer.ar: must be marked with \"عدّلني\".";
                if (stripos($en, 'EDIT ME') === false) $e[] = "starter_faq[$i].answer.en: must be marked with \"EDIT ME\".";
            }
        }

        // --- pricing ---
        if (!array_key_exists('pricing_supported', $tpl) || !is_bool($tpl['pricing_supported'])) $e[] = 'pricing_supported: must be true or false.';
        $bi($tpl['pricing_note'] ?? null, 'pricing_note');
        $pc = array_key_exists('pricing_config', $tpl) ? $tpl['pricing_config'] : false;
        if ($pc === false) $e[] = 'pricing_config: key must be present (object or null).';
        elseif (!empty($tpl['pricing_supported'])) {
            if (!is_array($pc)) $e[] = 'pricing_config: must be an object when pricing_supported is true.';
            else {
                // the real engine's validator, not a copy of it
                if (!function_exists('pricing_validate_config')) {
                    $p = APPPATH . 'helpers/pricing_helper.php';
                    if (is_file($p)) require_once $p;
                }
                if (function_exists('pricing_validate_config')) {
                    foreach (pricing_validate_config($pc) as $pe) $e[] = 'pricing_config: ' . $pe;
                } else {
                    $e[] = 'pricing_config: pricing_validate_config() unavailable, cannot verify.';
                }
            }
        } elseif ($pc !== null) {
            $e[] = 'pricing_config: must be null when pricing_supported is false (the calculator only supports accommodation/day_use).';
        }

        // --- working mode ---
        $wm = $tpl['working_mode'] ?? null;
        if (!is_array($wm)) $e[] = 'working_mode: missing.';
        else {
            if (!in_array($wm['recommended'] ?? '', array('always_on', 'staff_hours_silent'), true)) $e[] = 'working_mode.recommended: must be always_on or staff_hours_silent.';
            if (!in_array($wm['hours_enabled'] ?? '', array('0', '1'), true)) $e[] = "working_mode.hours_enabled: must be '0' or '1'.";
            foreach (array('hours_start', 'hours_end') as $f) {
                if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', (string) ($wm[$f] ?? ''))) $e[] = "working_mode.$f: must be HH:MM:SS.";
            }
            $days = array_filter(array_map('trim', explode(',', (string) ($wm['hours_days'] ?? ''))), 'strlen');
            if (empty($days)) $e[] = 'working_mode.hours_days: must list at least one ISO weekday (1=Mon..7=Sun).';
            foreach ($days as $d) if (!ctype_digit($d) || (int) $d < 1 || (int) $d > 7) $e[] = "working_mode.hours_days: \"$d\" is not 1-7.";
            if (($wm['recommended'] ?? '') === 'staff_hours_silent' && ($wm['hours_enabled'] ?? '') !== '1') $e[] = 'working_mode: staff_hours_silent needs hours_enabled = "1".';
            if (($wm['recommended'] ?? '') === 'always_on' && ($wm['hours_enabled'] ?? '') !== '0') $e[] = 'working_mode: always_on needs hours_enabled = "0".';
            $bi($wm['note'] ?? null, 'working_mode.note');
        }

        // --- pipeline ---
        $pl = $tpl['pipeline'] ?? null;
        if (!is_array($pl) || trim((string) ($pl['name'] ?? '')) === '') $e[] = 'pipeline.name: missing.';
        $stages = is_array($pl) ? ($pl['stages'] ?? null) : null;
        if (!is_array($stages) || count($stages) < 3) $e[] = 'pipeline.stages: need at least 3.';
        else {
            $types = array(); $names = array();
            foreach (array_values($stages) as $i => $s) {
                $n = trim((string) ($s['name'] ?? ''));
                if ($n === '') $e[] = "pipeline.stages[$i]: name is required.";
                elseif (isset($names[mb_strtolower($n)])) $e[] = "pipeline.stages[$i]: duplicate stage name \"$n\".";
                $names[mb_strtolower($n)] = true;
                if (!in_array($s['stage_type'] ?? '', array('open', 'won', 'lost'), true)) $e[] = "pipeline.stages[$i]: stage_type must be open/won/lost.";
                else $types[] = $s['stage_type'];
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s['color'] ?? ''))) $e[] = "pipeline.stages[$i]: color must be #rrggbb.";
            }
            foreach (array('won', 'lost') as $must) if (!in_array($must, $types, true)) $e[] = "pipeline.stages: must include one \"$must\" stage.";
        }

        // --- qualification questions ---
        $qq = $tpl['qualification_questions'] ?? null;
        if (!is_array($qq) || count($qq) < 3) $e[] = 'qualification_questions: need at least 3.';
        else foreach (array_values($qq) as $i => $q) {
            if (empty($q['key'])) $e[] = "qualification_questions[$i]: missing key.";
            $bi($q, "qualification_questions[$i]");
        }

        return $e;
    }
}

if (!function_exists('industry_template_compile_prompt')) {
    /**
     * Compile the template persona (+ the owner's overrides) into the three prompt fields
     * ai_agent_profiles already has. No new prompt storage, no new runtime path: the
     * existing reply pipeline reads these columns exactly as it does for a hand-written
     * agent.
     *
     * @param array $tpl       a loaded template
     * @param array $o         overrides: agent_name, role, goal, business_name, extra
     * @return array           ['agent_name'=>…, 'instruction_to_ai'=>…, 'sales_system_prompt'=>…]
     */
    function industry_template_compile_prompt($tpl, $o = array())
    {
        $ai = $tpl['ai_employee'] ?? array();
        $name = trim((string) ($o['agent_name'] ?? '')) !== ''
            ? trim((string) $o['agent_name'])
            : industry_template_text($ai['name_suggestion'] ?? null, 'ar');
        $role = trim((string) ($o['role'] ?? '')) !== '' ? trim((string) $o['role']) : industry_template_text($ai['role'] ?? null, 'ar');
        $goal = trim((string) ($o['goal'] ?? '')) !== '' ? trim((string) $o['goal']) : industry_template_text($ai['goal'] ?? null, 'ar');
        $biz = trim((string) ($o['business_name'] ?? ''));
        $extra = trim((string) ($o['extra'] ?? ''));

        $identity = array();
        $identity[] = 'اسمك: ' . $name;
        if ($biz !== '') $identity[] = 'بتشتغل في: ' . $biz;
        $identity[] = 'وظيفتك: ' . $role;
        $identity[] = 'هدفك: ' . $goal;
        $identity[] = 'أسلوبك: عربي مصري ودود وبسيط، ومختصر.';
        $instruction = implode("\n", $identity);

        $lines = array();
        $lines[] = '--- مين إنت ---';
        $lines[] = $instruction;

        $lines[] = '';
        $lines[] = '--- إنت بتعمل إيه ---';
        foreach (($ai['capabilities'] ?? array()) as $c) {
            $t = industry_template_text($c, 'ar');
            if ($t !== '') $lines[] = '- ' . $t;
        }

        $lines[] = '';
        $lines[] = '--- ممنوع تمامًا ---';
        $res = $ai['restrictions'] ?? array();
        $lines[] = '- أقصى خصم مسموح بيه: ' . (int) ($res['max_discount_percent'] ?? 0) . '%.';
        foreach (($res['rules'] ?? array()) as $r) {
            $t = industry_template_text($r, 'ar');
            if ($t !== '') $lines[] = '- ' . $t;
        }

        $qq = $tpl['qualification_questions'] ?? array();
        if (!empty($qq)) {
            $lines[] = '';
            $lines[] = '--- الأسئلة اللي لازم تجمع إجابتها قبل ما تقفل ---';
            $lines[] = 'اسأل سؤال واحد في كل رسالة، بالترتيب ده:';
            $i = 1;
            foreach ($qq as $q) {
                $t = industry_template_text($q, 'ar');
                if ($t !== '') $lines[] = ($i++) . '. ' . $t;
            }
        }

        if ($extra !== '') {
            $lines[] = '';
            $lines[] = '--- معلومات من صاحب الشغل ---';
            $lines[] = $extra;
        }

        return array(
            'agent_name' => $name,
            'instruction_to_ai' => $instruction,
            'sales_system_prompt' => implode("\n", $lines),
        );
    }
}

if (!function_exists('industry_template_faq_source_key')) {
    /** Stable per-tenant key for a starter FAQ row: survives the owner editing the text. */
    function industry_template_faq_source_key($template_key, $faq_key)
    {
        return 'tpl:' . $template_key . ':' . $faq_key;
    }
}

if (!function_exists('industry_template_faq_plan')) {
    /**
     * Decide what applying a template's starter FAQ should do — pure, no DB, so the
     * idempotency rule is testable on its own (tests/onboarding/run.php).
     *
     * Rules:
     *  - a starter question the tenant does not have yet  -> insert (keyed by source_key)
     *  - a starter question the tenant already has        -> update that row, never insert
     *  - a blank answer on an EXISTING row                -> leave the answer alone, so a
     *    second run cannot wipe what the owner wrote
     *  - a blank answer on a NEW row                      -> seed the "edit me" placeholder
     *  - FAQ rows with no source_key (hand-written)       -> never touched
     *
     * @param array $template          a loaded template
     * @param array $existing_by_key   source_key => row (the tenant's current tpl: rows)
     * @param array $answers           faq key => answer typed on the step (may be absent)
     * @return array[] {op:'insert'|'update', source_key, faq_key, question, answer|null, id}
     */
    function industry_template_faq_plan($template, $existing_by_key, $answers = array())
    {
        $plan = array();
        foreach (($template['starter_faq'] ?? array()) as $f) {
            $sk = industry_template_faq_source_key($template['key'], $f['key']);
            $question = industry_template_text($f['question'], 'ar');
            $typed = isset($answers[$f['key']]) ? trim((string) $answers[$f['key']]) : '';
            if (isset($existing_by_key[$sk])) {
                $plan[] = array(
                    'op' => 'update', 'source_key' => $sk, 'faq_key' => $f['key'],
                    'question' => $question,
                    'answer' => $typed !== '' ? $typed : null,   // null = keep what is stored
                    'id' => (int) ($existing_by_key[$sk]['id'] ?? 0),
                );
            } else {
                $plan[] = array(
                    'op' => 'insert', 'source_key' => $sk, 'faq_key' => $f['key'],
                    'question' => $question,
                    'answer' => $typed !== '' ? $typed : industry_template_text($f['answer'], 'ar'),
                    'id' => 0,
                );
            }
        }
        return $plan;
    }
}
