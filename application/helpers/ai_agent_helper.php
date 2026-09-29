<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-18 — resolve the AI agent profile assigned to a given channel target.
 | Returns a profile row (array) or null. Channel identifier semantics differ:
 |   fb  -> $page_id is the DB page_table_id (Messenger_bot webhook)
 |   ig  -> $page_id is the FB page_id string (Instagram_reply)
 |   wa/tg -> $page_id is the channel account id
 |   web -> $page_id is 'webchat' (single widget per user)
 */

if (!function_exists('ai_resolve_profile')) {
    function ai_resolve_profile($user_id, $social_media, $page_id)
    {
        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0) return null;
            $CI =& get_instance();
            // cheap gate: skip entirely if this account uses no assignments
            $has = $CI->db->from('ai_agent_assignments')->where('user_id', $user_id)->count_all_results();
            if ($has === 0) return null;

            $channel = in_array($social_media, array('fb','ig','wa','tg','web')) ? $social_media : 'fb';
            $target = null;
            if ($channel === 'fb' || $channel === 'ig') {
                // normalize either page_table_id or fb page_id string to page_table_id
                $row = $CI->db->select('id')->from('facebook_rx_fb_page_info')
                    ->where('user_id', $user_id)
                    ->group_start()->where('id', $page_id)->or_where('page_id', $page_id)->group_end()
                    ->limit(1)->get()->row_array();
                if (empty($row)) return null;
                $target = (string) $row['id'];
            } elseif ($channel === 'web') {
                // $page_id carries the widget_key so each website widget resolves its own
                // agent; assignments are keyed on widget_key (target_id). When a caller
                // passes the legacy 'webchat' sentinel or nothing, fall back to the first
                // widget's key so old single-widget setups keep working.
                if (!empty($page_id) && $page_id !== 'webchat') {
                    $target = (string) $page_id;
                } else {
                    $row = $CI->db->select('widget_key')->from('webchat_settings')
                        ->where('user_id', $user_id)->order_by('id', 'ASC')->limit(1)->get()->row_array();
                    if (empty($row)) return null;
                    $target = (string) $row['widget_key'];
                }
            } else { // wa / tg
                $target = (string) $page_id;
            }

            $CI->db->from('ai_agent_assignments')->where('user_id', $user_id)->where('target_id', $target);
            if ($channel === 'fb' || $channel === 'ig') {
                // one page assignment covers both Messenger and Instagram; exact channel wins
                $CI->db->where_in('channel_type', array('fb','ig'))->order_by("channel_type='".$channel."'", 'DESC');
            } else {
                $CI->db->where('channel_type', $channel);
            }
            $assign = $CI->db->limit(1)->get()->row_array();
            if (empty($assign)) return null;

            $profile = $CI->db->from('ai_agent_profiles')
                ->where('id', (int) $assign['profile_id'])->where('user_id', $user_id)->where('status', '1')
                ->limit(1)->get()->row_array();
            return empty($profile) ? null : $profile;
        } catch (Exception $e) {
            log_message('error', 'ai_resolve_profile: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('ai_agent_connected_channels')) {
    /**
     * Phase 11 - live channel status for the onboarding wizard's Channels step.
     * READ ONLY: it never creates or changes a connection; the connect flows stay exactly
     * where they are and the wizard only links to them.
     *
     * 'target' is the identifier ai_agent_assignments.target_id uses for that channel.
     * 'assignable' is false for openwa accounts: their ids share the 'wa' channel namespace
     * with whatsapp_accounts, so the assignment table cannot tell the two apart. They are
     * listed (the owner must see the channel is connected) but never auto-assigned.
     * Ai_agents::connected_channels() is deliberately left untouched - changing which
     * targets it offers would change existing assignment behaviour.
     *
     * 'kind' tells the two 'wa' sources apart (wa_cloud = whatsapp_accounts, openwa =
     * openwa_accounts) for callers with their own, collision-free namespace (price lists).
     *
     * @return array[] {channel,target,label,icon,note,assignable[,kind]}
     */
    function ai_agent_connected_channels($user_id)
    {
        $CI =& get_instance();
        $user_id = (int) $user_id;
        $out = array();
        foreach ($CI->db->select('id,page_id,page_name')->from('facebook_rx_fb_page_info')->where('user_id', $user_id)->get()->result_array() as $p)
            $out[] = array('channel' => 'fb', 'target' => $p['id'], 'label' => ($p['page_name'] ?: $p['page_id']), 'icon' => 'fab fa-facebook', 'note' => 'Messenger + Instagram', 'assignable' => true);
        if ($CI->db->table_exists('whatsapp_accounts'))
            foreach ($CI->db->select('id,label,display_phone')->from('whatsapp_accounts')->where('user_id', $user_id)->get()->result_array() as $w)
                $out[] = array('channel' => 'wa', 'target' => $w['id'], 'label' => ($w['label'] ?: $w['display_phone'] ?: ('WA #' . $w['id'])), 'icon' => 'fab fa-whatsapp', 'note' => 'WhatsApp', 'assignable' => true, 'kind' => 'wa_cloud');
        if ($CI->db->table_exists('openwa_accounts'))
            foreach ($CI->db->select('id,label,display_phone')->from('openwa_accounts')->where('user_id', $user_id)->get()->result_array() as $o)
                $out[] = array('channel' => 'wa', 'target' => $o['id'], 'label' => ($o['label'] ?: $o['display_phone'] ?: ('WhatsApp #' . $o['id'])), 'icon' => 'fab fa-whatsapp', 'note' => 'WhatsApp (personal number)', 'assignable' => false, 'kind' => 'openwa');
        if ($CI->db->table_exists('telegram_accounts'))
            foreach ($CI->db->select('id,bot_username')->from('telegram_accounts')->where('user_id', $user_id)->get()->result_array() as $t)
                $out[] = array('channel' => 'tg', 'target' => $t['id'], 'label' => ('@' . $t['bot_username']), 'icon' => 'fab fa-telegram', 'note' => 'Telegram', 'assignable' => true);
        if ($CI->db->table_exists('webchat_settings'))
            foreach ($CI->db->select('widget_key,title')->from('webchat_settings')->where('user_id', $user_id)->get()->result_array() as $wc)
                $out[] = array('channel' => 'web', 'target' => $wc['widget_key'], 'label' => ($wc['title'] ?: 'Web Chat Widget'), 'icon' => 'fas fa-comment-dots', 'note' => 'Website', 'assignable' => true);
        return $out;
    }
}

if (!function_exists('ai_agent_preview_provider')) {
    /**
     * The provider, model and decrypted key a reply for this open_ai_config row uses.
     * Mirrors Ai_provider::completion() exactly (anthropic only when ai_provider says so).
     *
     * @return array{provider:string, model:string, key:string}
     */
    function ai_agent_preview_provider($acc)
    {
        $acc = (array) $acc;
        if (($acc['ai_provider'] ?? '') === 'anthropic') {
            return array('provider' => 'anthropic',
                'model' => !empty($acc['anthropic_model']) ? $acc['anthropic_model'] : 'claude-haiku-4-5',
                'key' => trim((string) secret_decrypt((string) ($acc['anthropic_secret_key'] ?? ''))));
        }
        return array('provider' => 'openai',
            'model' => !empty($acc['models']) ? $acc['models'] : 'gpt-4o-mini',
            'key' => trim((string) secret_decrypt((string) ($acc['open_ai_secret_key'] ?? ''))));
    }
}

if (!function_exists('ai_agent_preview_reply')) {
    /**
     * SPEC-26 preview, extracted from Ai_agents::preview (Phase 11) so the onboarding
     * wizard's "Test conversation" step runs the SAME path instead of a second copy.
     * Assembles master + bot-specific + guardrail layers from the fields passed in, makes
     * one model call, returns the reply.
     *
     * Never fabricates: with no credentials / no key it returns status '0' and the reason.
     *
     * @param int    $user_id  tenant
     * @param string $message  the test message
     * @param array  $fields   agent_name, instruction_to_ai, sales_system_prompt
     * @return array {status:'1',reply,chars} | {status:'0',message}
     */
    function ai_agent_preview_reply($user_id, $message, $fields = array())
    {
        $CI =& get_instance();
        $user_id = (int) $user_id;
        $message = trim((string) $message);
        if ($message === '') return array('status' => '0', 'message' => 'Type a test message');

        $acc = $CI->db->from('open_ai_config')->where('user_id', $user_id)->limit(1)->get()->row_array();
        if (empty($acc)) return array('status' => '0', 'message' => 'No AI credentials configured');

        // Same provider + key the live reply path uses (Ai_provider: open_ai_config.ai_provider,
        // decrypted open_ai_secret_key / anthropic_secret_key). Checked here so the owner gets a
        // plain reason instead of a provider error, and no request is made without a key.
        $CI->load->helper('secret');
        $plan = ai_agent_preview_provider($acc);
        if ($plan['key'] === '') return array('status' => '0', 'message' => $plan['provider'] === 'anthropic' ? 'No Anthropic key' : 'No OpenAI key');

        $agent_name  = trim((string) ($fields['agent_name'] ?? ''));
        $instruction = trim((string) ($fields['instruction_to_ai'] ?? ''));
        $agent_sales = trim((string) ($fields['sales_system_prompt'] ?? ''));

        $layers = array();
        if (!empty($acc['sales_system_prompt'])) $layers[] = "--- MASTER RULES (apply to every page and every agent; never overridden) ---\n" . trim($acc['sales_system_prompt']);
        $bot = array();
        if ($agent_name !== '')  $bot[] = 'AGENT IDENTITY: ' . $agent_name;
        if ($instruction !== '') $bot[] = $instruction;
        if ($agent_sales !== '') $bot[] = $agent_sales;
        if (!empty($bot)) $layers[] = "--- BOT-SPECIFIC INSTRUCTIONS (this business's identity, offers, prices, and rules) ---\n" . implode("\n\n", $bot);
        $sp = implode("\n\n", $layers);
        // condensed guardrails so the preview behaves like production on scope/price/format
        $sp .= "\n\nSTRICT SCOPE RULES: Only use the identity, products, services and prices written above. Never invent a price or a fact. If a price is not written, say the team will confirm and ask for a WhatsApp number. Every reply is very short: one direct answer + one question, no lists, no markdown. Mirror the customer's language; Arabic -> Egyptian colloquial.";

        // One call through Ai_provider: provider selection, model default, usage accounting
        // (purpose agent_preview) and error shape are exactly the live path's. No tools, no
        // history, no KB - see Ai_agents::preview.
        $CI->load->library('Ai_provider');
        $raw = $CI->ai_provider->completion($acc, array(
            array('role' => 'system', 'content' => $sp),
            array('role' => 'user', 'content' => $message),
        ), array('temperature' => 0.4, 'purpose' => 'agent_preview'));
        $d = json_decode((string) $raw, true);
        $reply = is_array($d) ? trim((string) ($d['choices'][0]['text'] ?? '')) : '';
        if ($reply === '') {
            $why = is_array($d) && isset($d['error']['message']) ? (string) $d['error']['message'] : 'empty reply';
            return array('status' => '0', 'message' => 'Preview failed (' . $plan['provider'] . ': ' . mb_substr($why, 0, 200) . ')');
        }
        return array('status' => '1', 'reply' => $reply, 'chars' => mb_strlen($sp));
    }
}

// ---------------------------------------------------------------------------------------
// Task 27 (C12) — AI Employee Builder: structured fields, per-tool toggles, deterministic
// restrictions enforced after generation. See migrations/2026-10-05b-agent-builder.sql.
//
// REVIEW GATE: a profile with NULL capabilities_json / restrictions_json (every profile
// saved before this task, or one whose owner never touched the builder fields) must behave
// exactly as before this task: the same tool set Ai_tools::catalog() always offered, and no
// new post-generation gate ever runs. Every function below only acts when the relevant
// column is both present (migration applied) and non-NULL/non-empty for that profile.
// ---------------------------------------------------------------------------------------

if (!function_exists('ai_agent_builder_columns_exist')) {
    /** Cached per-request: has the C12 migration run? One field_exists() call ever. */
    function ai_agent_builder_columns_exist()
    {
        static $exists = null;
        if ($exists === null) {
            try {
                $CI =& get_instance();
                $exists = (bool) $CI->db->field_exists('capabilities_json', 'ai_agent_profiles');
            } catch (Exception $e) {
                $exists = false;
            }
        }
        return $exists;
    }
}

if (!function_exists('ai_agent_capabilities')) {
    /**
     * Decode a profile's capabilities_json into an array of enabled tool names, or NULL
     * when the profile has no per-tool restriction (migration not applied, column empty,
     * or invalid JSON) — NULL is the "offer today's default tool set" sentinel used by
     * Ai_tools::catalog().
     *
     * @return string[]|null
     */
    function ai_agent_capabilities($ai_profile)
    {
        if (!is_array($ai_profile) || empty($ai_profile['capabilities_json'])) return null;
        $dec = json_decode((string) $ai_profile['capabilities_json'], true);
        if (!is_array($dec)) return null;
        // tolerate {"tools":[...]} as well as a bare list
        if (isset($dec['tools']) && is_array($dec['tools'])) $dec = $dec['tools'];
        $out = array();
        foreach ($dec as $name) {
            if (is_string($name) && $name !== '') $out[] = $name;
        }
        return $out; // may be empty array = "no tools enabled", distinct from NULL
    }
}

if (!function_exists('ai_agent_restrictions')) {
    /**
     * Decode a profile's restrictions_json. Missing/invalid -> all-empty array so callers
     * can always index it; each key is only enforced by the caller when actually set.
     *
     * @return array{allowed_link_domains:array,max_discount:?float,forbidden_topics:array,escalation_rules:string}
     */
    function ai_agent_restrictions($ai_profile)
    {
        $out = array('allowed_link_domains' => array(), 'max_discount' => null, 'forbidden_topics' => array(), 'escalation_rules' => '');
        if (!is_array($ai_profile) || empty($ai_profile['restrictions_json'])) return $out;
        $dec = json_decode((string) $ai_profile['restrictions_json'], true);
        if (!is_array($dec)) return $out;
        if (!empty($dec['allowed_link_domains']) && is_array($dec['allowed_link_domains'])) {
            foreach ($dec['allowed_link_domains'] as $d) { $d = trim((string) $d); if ($d !== '') $out['allowed_link_domains'][] = strtolower($d); }
        }
        if (isset($dec['max_discount']) && is_numeric($dec['max_discount'])) $out['max_discount'] = (float) $dec['max_discount'];
        if (!empty($dec['forbidden_topics']) && is_array($dec['forbidden_topics'])) {
            foreach ($dec['forbidden_topics'] as $t) { $t = trim((string) $t); if ($t !== '') $out['forbidden_topics'][] = $t; }
        }
        if (!empty($dec['escalation_rules'])) $out['escalation_rules'] = (string) $dec['escalation_rules'];
        return $out;
    }
}

if (!function_exists('ai_agent_url_host')) {
    /** Bare registrable-ish host for a URL, lowercased, no leading www. */
    function ai_agent_url_host($url)
    {
        $host = (string) parse_url((string) $url, PHP_URL_HOST);
        $host = strtolower($host);
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        return $host;
    }
}

if (!function_exists('ai_agent_enforce_restrictions')) {
    /**
     * Deterministic, post-generation enforcement of a profile's restrictions. Runs after
     * the model produced text and after the existing price/media guards. Never throws
     * (fails open on internal errors — the reply already passed the built-in guards).
     *
     * Order: forbidden_topics -> max_discount -> allowed_link_domains. The first match
     * that requires a hand-off stops further checks (one hand-off per reply is enough).
     *
     * @return array{text:string, blocked:bool, reason:string}
     */
    function ai_agent_enforce_restrictions($ai_profile, $user_id, $social_media, $page_id, $subscribe_id, $human, $text)
    {
        $res = array('text' => $text, 'blocked' => false, 'reason' => '');
        if (!is_array($ai_profile) || !ai_agent_builder_columns_exist()) return $res;
        $r = ai_agent_restrictions($ai_profile);
        if (empty($r['forbidden_topics']) && $r['max_discount'] === null && empty($r['allowed_link_domains'])) return $res;

        try {
            $CI =& get_instance();
            $CI->load->helper('ai_safety');

            // 1) forbidden topics — keyword match against what the customer asked AND what
            // the bot is about to say, so a topic mentioned only in the reply is still caught.
            if (!empty($r['forbidden_topics'])) {
                $hay = mb_strtolower((string) $human . ' ' . (string) $text, 'UTF-8');
                foreach ($r['forbidden_topics'] as $topic) {
                    if ($topic !== '' && mb_strpos($hay, mb_strtolower($topic, 'UTF-8')) !== false) {
                        $res['text'] = ai_price_guard_handoff_text($human);
                        $res['blocked'] = true;
                        $res['reason'] = 'forbidden_topic:' . $topic;
                        ai_price_guard_flag_handoff($user_id, $social_media, $page_id, $subscribe_id, 'agent_builder: forbidden topic "' . $topic . '"');
                        return $res;
                    }
                }
            }

            // 2) max_discount — reuses the price guard's hand-off path. Any percentage in
            // the reply above the ceiling (e.g. "20% off", "خصم 25%") is unverifiable by
            // definition (the ceiling is a policy, not a written price) so it is handed off
            // rather than sent, exactly like an unverifiable price.
            if ($r['max_discount'] !== null && preg_match_all('/(\d{1,3}(?:\.\d+)?)\s*%/u', (string) $res['text'], $pm)) {
                foreach ($pm[1] as $pct) {
                    if ((float) $pct > $r['max_discount']) {
                        $res['text'] = ai_price_guard_handoff_text($human);
                        $res['blocked'] = true;
                        $res['reason'] = 'max_discount:' . $pct;
                        ai_price_guard_flag_handoff($user_id, $social_media, $page_id, $subscribe_id, 'agent_builder: discount ' . $pct . '% exceeds ceiling ' . $r['max_discount'] . '%');
                        return $res;
                    }
                }
            }

            // 3) allowed_link_domains — strip/replace any link whose host is not on the
            // allow-list. Unlike the SPEC-31 media gate (which checks against configured
            // albums) this is an owner-set allow-list of domains; a non-matching link is
            // simply removed rather than escalated, since a link is rarely the whole reply.
            if (!empty($r['allowed_link_domains'])) {
                $CI->load->helper('media');
                $urls = media_extract_urls($res['text']);
                foreach ($urls as $u) {
                    $host = ai_agent_url_host($u);
                    $ok = false;
                    foreach ($r['allowed_link_domains'] as $d) {
                        if ($host === $d || (strlen($host) > strlen($d) && substr($host, -strlen($d) - 1) === '.' . $d)) { $ok = true; break; }
                    }
                    if (!$ok) {
                        $res['text'] = str_replace($u, '', $res['text']);
                        $res['reason'] = trim($res['reason'] . ' link_stripped:' . $host);
                    }
                }
                $res['text'] = trim(preg_replace('/\s{2,}/', ' ', $res['text']));
            }
        } catch (Throwable $e) {
            log_message('error', 'ai_agent_enforce_restrictions: ' . $e->getMessage());
            return array('text' => $text, 'blocked' => false, 'reason' => 'error');
        }
        return $res;
    }
}
