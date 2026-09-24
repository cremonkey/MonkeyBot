<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Records AI provider and tool failures that would otherwise be swallowed silently
 | (Ai_provider's error responses, Ai_tools::execute()'s catch-all) so an admin can see
 | them (brief 51: no silent failure). Never throws, never changes what the customer
 | receives, never stores secrets.
 |
 | Table: ai_failure_log (migration 2026-10-02b-ai-failure-log.sql). Code works before the
 | migration runs: table_exists() is checked fresh on every call, NOT cached in a static.
 | Deliberate deviation from plan rule 4's usual "cache the result in a static": this only
 | runs on the failure path (a handful of times per AI turn), not a hot per-page-load loop
 | like Inbox.php, so a fresh check every time costs nothing and the table is picked up the
 | moment the migration lands, with no worker restart needed.
 */

if (!function_exists('ai_failure_scrub')) {
    /** Strip anything that looks like a credential before it ever reaches the log. */
    function ai_failure_scrub($text)
    {
        $text = (string) $text;
        // Bearer/Authorization-style tokens.
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9\-_\.=]+/i', 'Bearer [REDACTED]', $text);
        // OpenAI-style secret keys (sk-..., sk-proj-..., sk-ant-...).
        $text = preg_replace('/\bsk-[A-Za-z0-9\-_]{8,}/', 'sk-[REDACTED]', $text);
        // x-api-key / api_key / apikey / token / secret style "key=value" fragments.
        $text = preg_replace('/\b((?:api[_\-]?key|x-api-key|access[_\-]?token|secret|token)\s*[:=]\s*)["\']?[A-Za-z0-9\-_\.]{8,}["\']?/i', '$1[REDACTED]', $text);
        // Any long opaque alnum run (32+ chars) is more likely a credential than useful text.
        $text = preg_replace('/\b[A-Za-z0-9\-_]{32,}\b/', '[REDACTED]', $text);
        return $text;
    }
}

if (!function_exists('ai_failure_record')) {
    /**
     * @param int    $user_id
     * @param string $kind          'provider' | 'tool'
     * @param array  $fields        provider, model, purpose, tool_name, args_hash, error_class,
     *                              error_message, http_status
     */
    function ai_failure_record($user_id, $kind, array $fields = array())
    {
        try {
            $CI =& get_instance();
            // Not cached in a static: this runs at most a few times per AI turn (one provider
            // call, a handful of tool calls), unlike Inbox.php's per-page-load hot paths, so a
            // fresh table_exists() check every time costs nothing and self-heals the moment the
            // migration lands, without needing a worker restart.
            if (!isset($CI->db) || !$CI->db->table_exists('ai_failure_log')) return 0;

            $user_id = (int) $user_id;
            $kind = $kind === 'tool' ? 'tool' : 'provider';
            if ($user_id <= 0) return 0;

            $msg = ai_failure_scrub(isset($fields['error_message']) ? $fields['error_message'] : '');
            $row = array(
                'user_id'       => $user_id,
                'kind'          => $kind,
                'provider'      => mb_substr((string) ($fields['provider'] ?? ''), 0, 32),
                'model'         => mb_substr((string) ($fields['model'] ?? ''), 0, 64),
                'purpose'       => mb_substr((string) ($fields['purpose'] ?? ''), 0, 40),
                'tool_name'     => mb_substr((string) ($fields['tool_name'] ?? ''), 0, 64),
                'args_hash'     => mb_substr((string) ($fields['args_hash'] ?? ''), 0, 64),
                'error_class'   => mb_substr((string) ($fields['error_class'] ?? ''), 0, 80),
                'error_message' => mb_substr($msg, 0, 500),
                'http_status'   => isset($fields['http_status']) && $fields['http_status'] !== null ? (int) $fields['http_status'] : null,
                'created_at'    => date('Y-m-d H:i:s'),
            );
            // Task 13: correlation id (2026-10-03d-request-ids.sql). Fresh field_exists() check
            // each time, matching this file's own deviation from the usual static cache (see
            // header comment): failures are rare, so this costs nothing and self-heals the
            // moment the migration lands.
            if ($CI->db->field_exists('request_id', 'ai_failure_log') && function_exists('request_id_get')) {
                $row['request_id'] = request_id_get();
            }
            $CI->db->insert('ai_failure_log', $row);
            $id = (int) $CI->db->insert_id();

            if ($kind === 'provider' && function_exists('event_emit')) {
                $CI->load->helper('event');
                event_emit($user_id, 'ai.failed', 'subscriber', '', array(
                    'provider' => $row['provider'],
                    'model'    => $row['model'],
                    'purpose'  => $row['purpose'],
                    'error'    => $row['error_message'],
                ));
            }
            return $id;
        } catch (Throwable $e) {
            log_message('error', 'ai_failure_record(' . $kind . '): ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('ai_failure_args_hash')) {
    /** Short, non-reversible fingerprint of tool args (never store raw args: they can carry PII/secrets). */
    function ai_failure_args_hash($args)
    {
        $json = is_string($args) ? $args : json_encode($args, JSON_UNESCAPED_UNICODE);
        if ($json === false) $json = '';
        return substr(sha1((string) $json), 0, 16);
    }
}
