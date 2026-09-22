<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Unified Inbox (Phase 9.5, brief §13/§14/§46) — pure / DB-shape logic factored out of
 * Inbox.php so it is unit-testable without a full CodeIgniter bootstrap
 * (tests/inbox/run.php). The controller calls these same functions: a passing test proves
 * the real code path, not a parallel reimplementation.
 *
 * Nothing here touches the database directly except the *_scope_* helpers, which take the
 * query builder as an argument (duck-typed: where/like/or_like/group_start/group_end/or_where)
 * so tests can pass a recording fake instead of a real CI DB object.
 */

if (!function_exists('inbox_status_action_map')) {
    /** UI action -> target handoff_state. Every action here is agent-initiated (brief §14). */
    function inbox_status_action_map()
    {
        return array(
            'resolve'       => 'RESOLVED',
            'reopen'        => 'AI_ACTIVE',
            'takeover'      => 'HUMAN_ACTIVE',
            'return_to_bot' => 'AI_ACTIVE',
            'assist'        => 'AI_ASSIST',
        );
    }
}

if (!function_exists('inbox_status_action_to_state')) {
    function inbox_status_action_to_state($action)
    {
        $map = inbox_status_action_map();
        return isset($map[$action]) ? $map[$action] : null;
    }
}

if (!function_exists('inbox_handoff_label')) {
    /** Owner-language label for a handoff_state (brief §46: no bot_paused_until/XeroChat jargon). */
    function inbox_handoff_label($state)
    {
        $m = array(
            'AI_ACTIVE'         => 'AI is replying',
            'WAITING_FOR_HUMAN' => 'Waiting for a teammate',
            'HUMAN_ACTIVE'      => "You're handling this",
            'AI_ASSIST'         => 'AI is helping you',
            'RESOLVED'          => 'Closed',
        );
        return isset($m[$state]) ? $m[$state] : $state;
    }
}

if (!function_exists('inbox_takeover_path')) {
    /**
     * Ordered handoff_state hops an agent must walk through to reach HUMAN_ACTIVE from
     * $from_state, following the brief §14 edges exactly (conversation_helper.php's
     * conversation_transition_map): AI_ACTIVE has no direct edge to HUMAN_ACTIVE, only via
     * WAITING_FOR_HUMAN. Every hop here is verified allowed for actor_type='agent' in
     * tests/inbox/run.php against conversation_transition_allowed().
     */
    function inbox_takeover_path($from_state)
    {
        $paths = array(
            'AI_ACTIVE'         => array('WAITING_FOR_HUMAN', 'HUMAN_ACTIVE'),
            'WAITING_FOR_HUMAN' => array('HUMAN_ACTIVE'),
            'AI_ASSIST'         => array('HUMAN_ACTIVE'),
            'RESOLVED'          => array('AI_ACTIVE', 'WAITING_FOR_HUMAN', 'HUMAN_ACTIVE'),
            'HUMAN_ACTIVE'      => array(),
        );
        return isset($paths[$from_state]) ? $paths[$from_state] : array('HUMAN_ACTIVE');
    }
}

if (!function_exists('inbox_resolve_path')) {
    /** Ordered hops to RESOLVED. Only HUMAN_ACTIVE -> RESOLVED exists, so from any other
     *  state we take over first (brief §14 has no AI_ASSIST -> RESOLVED or WAITING -> RESOLVED edge). */
    function inbox_resolve_path($from_state)
    {
        if ($from_state === 'HUMAN_ACTIVE') return array('RESOLVED');
        return array_merge(inbox_takeover_path($from_state), array('RESOLVED'));
    }
}

if (!function_exists('inbox_platform_to_policy_key')) {
    /**
     * conversations.platform ('fb'|'ig'|'wa'|'tg'|'web') -> channel_policy_decide() /
     * ChannelRegistry key. 'wa' is ambiguous (OpenWA vs Cloud share the platform value per
     * ADR-001's "wa account-id collision" note); callers that know the resolved adapter pass
     * it through $wa_adapter_key.
     */
    function inbox_platform_to_policy_key($platform, $wa_adapter_key = 'wa')
    {
        if ($platform === 'wa') return $wa_adapter_key === 'wa_cloud' ? 'wa_cloud' : 'wa';
        return $platform;
    }
}

if (!function_exists('inbox_reply_decision')) {
    /**
     * Whether a human agent's reply may be sent right now. The brief requires every human
     * reply to go through channel_policy_decide(sender='human') first; on deny the caller
     * must show WHY (policy_explain) and must NOT fabricate a sent message.
     * @return array{allowed:bool, reason:string, window:string, messaging_type:?string, tag:?string, explain:string}
     */
    function inbox_reply_decision($policy_platform, $last_inbound_at, $opted_out = false, $now = null)
    {
        $ctx = array('sender' => 'human', 'last_inbound_at' => $last_inbound_at, 'opted_out' => (bool) $opted_out);
        if ($now !== null) $ctx['now'] = $now;
        $d = channel_policy_decide($policy_platform, $ctx);
        $d['explain'] = channel_policy_explain($d['reason']);
        return $d;
    }
}

if (!function_exists('inbox_scope_conversations')) {
    /**
     * Apply the tenant guard + list filters to a query builder. user_id is ALWAYS applied
     * first, unconditionally — no filter path may bypass it (no IDOR, brief §38/§57).
     * Expects the builder already has FROM conversations c (customers joined as cu when a
     * search is requested).
     *
     * $filters: platform, status, handoff_state, assigned_user_id, unread_only (bool), search
     */
    function inbox_scope_conversations($db, $user_id, array $filters = array())
    {
        $db->where('c.user_id', (int) $user_id);

        if (!empty($filters['platform'])) $db->where('c.platform', (string) $filters['platform']);
        if (!empty($filters['status'])) $db->where('c.status', (string) $filters['status']);
        if (!empty($filters['handoff_state'])) $db->where('c.handoff_state', (string) $filters['handoff_state']);
        if (array_key_exists('assigned_user_id', $filters) && $filters['assigned_user_id'] !== null && $filters['assigned_user_id'] !== '') {
            $db->where('c.assigned_user_id', (int) $filters['assigned_user_id']);
        }
        if (!empty($filters['unread_only'])) $db->where('c.unread_count >', 0);

        if (!empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $db->group_start();
            $db->like('cu.display_name', $s);
            $db->or_like('cu.phone', $s);
            $db->or_where(
                "EXISTS (SELECT 1 FROM livechat_messages lm WHERE lm.conversation_id = c.id AND lm.message_content LIKE " .
                    "'%" . addcslashes($s, "%_\\'") . "%' LIMIT 1)"
            );
            $db->group_end();
        }
        return $db;
    }
}

if (!function_exists('inbox_scope_conversation_owner')) {
    /** Tenant + id guard for a single conversation (thread view, reply, assign, notes, status). */
    function inbox_scope_conversation_owner($db, $conversation_id, $user_id)
    {
        return $db->where('id', (int) $conversation_id)->where('user_id', (int) $user_id);
    }
}
