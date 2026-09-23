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
