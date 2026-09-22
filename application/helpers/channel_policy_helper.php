<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Channel Policy Engine (Phase 10.5 / brief 26). ONE place that answers "may we send this
 | message to this person on this channel right now, and how?". Callers (inbox replies,
 | sequences, re-engage, follow-ups) must not re-implement Meta/WhatsApp policy.
 |
 | Safe default: anything we cannot verify resolves to "not allowed". A false allow costs a
 | platform policy violation (page restrictions); a false deny costs one delayed message.
 |
 | Built on reengage_classify() (the audited 24h / 7-day windows) so there is one clock.
 */

if (!function_exists('channel_policy_decide')) {
    /**
     * @param string $platform fb | ig | wa (OpenWA) | wa_cloud | tg | web | tiktok
     * @param array  $ctx {
     *   sender: 'human' | 'automation'   (AI replies to a fresh inbound count as automation in-window)
     *   last_inbound_at: datetime|int|null  last message FROM the customer
     *   opted_out: bool                    customer asked to stop automated messages
     *   now: int|null                      unix time (tests)
     * }
     * @return array{allowed:bool, reason:string, window:string, messaging_type:?string, tag:?string}
     */
    function channel_policy_decide($platform, array $ctx)
    {
        if (!function_exists('reengage_classify')) {
            $CI =& get_instance();
            $CI->load->helper('reengage');
        }
        $sender = ($ctx['sender'] ?? 'automation') === 'human' ? 'human' : 'automation';
        $now = isset($ctx['now']) ? (int) $ctx['now'] : null;
        $out = function ($allowed, $reason, $window, $type = null, $tag = null) {
            return array('allowed' => $allowed, 'reason' => $reason, 'window' => $window, 'messaging_type' => $type, 'tag' => $tag);
        };

        if (!empty($ctx['opted_out']) && $sender === 'automation') return $out(false, 'opted_out', 'n/a');

        switch ($platform) {
            case 'fb':
            case 'ig':
                $w = reengage_classify($ctx['last_inbound_at'] ?? null, $now);
                if ($w === REENGAGE_IN_WINDOW) return $out(true, 'in_24h_window', $w, 'RESPONSE');
                if ($w === REENGAGE_HUMAN_AGENT) {
                    return $sender === 'human'
                        ? $out(true, 'human_agent_tag_within_7_days', $w, 'MESSAGE_TAG', 'HUMAN_AGENT')
                        : $out(false, 'automation_outside_24h_window', $w);
                }
                return $out(false, 'outside_7_day_window', $w);

            case 'wa_cloud':
                $w = reengage_classify($ctx['last_inbound_at'] ?? null, $now);
                return $w === REENGAGE_IN_WINDOW
                    ? $out(true, 'in_24h_customer_service_window', $w)
                    : $out(false, 'template_required_outside_24h', $w);

            case 'wa':   // OpenWA (WhatsApp Web session): no Meta messaging window, but unsolicited
                         // automation risks the number being banned — only to people who wrote first.
                if ($sender === 'automation' && empty($ctx['last_inbound_at'])) return $out(false, 'never_contacted_us', 'n/a');
                return $out(true, 'session_channel', 'n/a');

            case 'tg':   // bots can only message users who started the chat; an inbound proves it
                if (empty($ctx['last_inbound_at'])) return $out(false, 'user_never_started_bot', 'n/a');
                return $out(true, 'chat_started', 'n/a');

            case 'web':  // delivered when the visitor's widget polls; only to an existing session
                if (empty($ctx['last_inbound_at'])) return $out(false, 'no_widget_session', 'n/a');
                return $out(true, 'widget_session', 'n/a');

            case 'tiktok':
                return $out(false, 'channel_send_unsupported', 'n/a');
        }
        return $out(false, 'unknown_channel', 'n/a');
    }
}

if (!function_exists('channel_policy_explain')) {
    /** Owner-language explanation for the inbox and settings screens. */
    function channel_policy_explain($reason)
    {
        $m = array(
            'in_24h_window' => 'Customer wrote in the last 24 hours — any reply is allowed.',
            'human_agent_tag_within_7_days' => 'More than 24 hours since the customer wrote — a person on your team can still reply (up to 7 days).',
            'automation_outside_24h_window' => 'More than 24 hours since the customer wrote — automated messages are not allowed by Meta.',
            'outside_7_day_window' => 'More than 7 days since the customer wrote — Meta does not allow replying until they message again.',
            'in_24h_customer_service_window' => 'Customer wrote in the last 24 hours — free-text replies are allowed.',
            'template_required_outside_24h' => 'More than 24 hours since the customer wrote — WhatsApp only allows an approved template.',
            'never_contacted_us' => 'This number never wrote to you — automated messages could get the number banned.',
            'user_never_started_bot' => 'The person has not started your Telegram bot.',
            'no_widget_session' => 'No active website chat session for this visitor.',
            'channel_send_unsupported' => 'Sending messages is not supported on this channel.',
            'opted_out' => 'The customer asked not to receive automated messages.',
            'session_channel' => 'Allowed.', 'chat_started' => 'Allowed.', 'widget_session' => 'Allowed.',
            'unknown_channel' => 'Unknown channel.',
        );
        return $m[$reason] ?? $reason;
    }
}
