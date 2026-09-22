<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Event Engine (Phase 10.5 / brief 22).
 |
 | ONE append-only fact log every feature can write to and every feature can read from,
 | so features stop calling each other directly. A webhook that books an appointment does
 | not need to know that an automation rule wants to hear about it: it emits
 | `appointment.created` and the Automation worker does the rest.
 |
 | THE CONTRACT FOR CALLERS
 |   event_emit() is a single INSERT and it NEVER throws. It is safe to call from a webhook
 |   handler, from inside a DB transaction, or from a hot AI-reply path. If the table is
 |   missing, the DB is down, or the payload will not encode, the event is dropped and a
 |   line is logged — the caller's own flow continues untouched. Emitting is deliberately
 |   NOT behind the `automation_engine_enabled` flag (brief 50): writing history is cheap
 |   and we want the log to exist before anyone switches the automations on.
 |
 | THE CATALOGUE (brief 22). `subject_type`/`subject_id` say what the event is about, so a
 | rule can load context for it. `payload` is free-form JSON; the keys below are the ones
 | rules may reference by name (see docs/automations/engine.md).
 |
 |   event_type              subject_type   payload keys (beyond the common ones)
 |   ----------------------  -------------  -------------------------------------------
 |   message.received        subscriber     platform, text, page_id
 |   message.sent            subscriber     platform, text, sender (bot|human|automation)
 |   conversation.started    subscriber     platform
 |   conversation.assigned   subscriber     agent_id
 |   conversation.resolved   subscriber     agent_id, duration_s
 |   customer.created        subscriber     platform, full_name
 |   lead.created            deal           deal_id, stage, trigger, value, source
 |   lead.qualified          subscriber     lead_score, band
 |   lead.stage_changed      deal           deal_id, from_stage, to_stage
 |   price.requested         subscriber     query, quoted_total
 |   order.created           order          order_id, total, currency
 |   checkout.started        cart           cart_id, total
 |   checkout.abandoned      cart           cart_id, total, idle_minutes
 |   appointment.created     appointment    appointment_id, service_id, starts_at, source
 |   human.requested         subscriber     platform, reason
 |   human.takeover          subscriber     agent_id
 |   ai.failed               subscriber     provider, error
 |   question.unanswered     subscriber     question, topic_key
 |
 | Common payload keys, set by the emitter whenever it knows them, because the conditions
 | in a rule are usually written against these: subscriber_id, platform, full_name,
 | lead_score, phone, email.
 |
 | WHAT IS ACTUALLY EMITTED TODAY — and what is still a TODO — is listed in
 | docs/automations/engine.md ("Wiring status"). Read that before assuming a trigger fires.
 */

if (!function_exists('event_emit')) {
    /**
     * Append one event to the log. Never throws, never blocks the caller.
     *
     * @param int         $user_id        owning tenant (messenger_bot_subscriber.user_id etc.)
     * @param string      $type           catalogue key, e.g. 'lead.created'
     * @param string      $subject_type   'subscriber' | 'deal' | 'order' | 'cart' | 'appointment' | ''
     * @param string|int  $subject_id     id of that subject in its own table
     * @param array       $payload        free-form, JSON-encoded
     * @param string|null $correlation_id ties events from one conversation/request together
     * @return int inserted event id, or 0 when the event could not be stored
     */
    function event_emit($user_id, $type, $subject_type = '', $subject_id = '', array $payload = array(), $correlation_id = null)
    {
        try {
            $user_id = (int) $user_id;
            $type = trim((string) $type);
            if ($user_id <= 0 || $type === '') return 0;

            $CI =& get_instance();
            if (!isset($CI->db) || !$CI->db->table_exists('events')) return 0;

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($json === false) $json = '{}';

            $CI->db->insert('events', array(
                'user_id'        => $user_id,
                'event_type'     => mb_substr($type, 0, 64),
                'subject_type'   => mb_substr((string) $subject_type, 0, 32),
                'subject_id'     => mb_substr((string) $subject_id, 0, 64),
                'payload'        => $json,
                'correlation_id' => $correlation_id === null ? null : mb_substr((string) $correlation_id, 0, 64),
                'created_at'     => date('Y-m-d H:i:s'),
                'processed_at'   => null,
            ));
            return (int) $CI->db->insert_id();
        } catch (Throwable $e) {
            // An event is a side channel. Losing one must never cost the caller its own work.
            log_message('error', 'event_emit(' . $type . '): ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('event_catalogue')) {
    /**
     * event_type => owner-language label, for the trigger dropdown (brief 46: no jargon).
     * Order is the order shown in the UI.
     */
    function event_catalogue()
    {
        return array(
            'message.received'      => 'A customer sent a message',
            'message.sent'          => 'We sent a message',
            'conversation.started'  => 'A new conversation started',
            'conversation.assigned' => 'A conversation was assigned to someone',
            'conversation.resolved' => 'A conversation was marked done',
            'customer.created'      => 'A new customer appeared',
            'lead.created'          => 'A new lead was created',
            'lead.qualified'        => 'A lead became qualified',
            'lead.stage_changed'    => 'A lead moved to another stage',
            'price.requested'       => 'Someone asked about prices',
            'order.created'         => 'An order was placed',
            'checkout.started'      => 'Someone started checkout',
            'checkout.abandoned'    => 'Someone left without buying',
            'appointment.created'   => 'An appointment was booked',
            'human.requested'       => 'A customer asked for a person',
            'human.takeover'        => 'A team member took over the chat',
            'ai.failed'             => 'The AI could not answer (technical failure)',
            'question.unanswered'   => 'The bot did not know the answer',
        );
    }
}

if (!function_exists('event_label')) {
    /** Owner-language label for one event type, falling back to the raw key. */
    function event_label($type)
    {
        $c = event_catalogue();
        return isset($c[$type]) ? $c[$type] : (string) $type;
    }
}

if (!function_exists('event_payload')) {
    /** Decode an events row's payload to an array (never null). */
    function event_payload($row)
    {
        $raw = is_array($row) ? (isset($row['payload']) ? $row['payload'] : '') : $row;
        $d = json_decode((string) $raw, true);
        return is_array($d) ? $d : array();
    }
}
