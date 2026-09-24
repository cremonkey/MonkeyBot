<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Request/correlation id (remaining-work Task 13, brief 39/51).
 |
 | One id, generated once at each webhook entry point (Home::central_webhook_callback,
 | Openwa_bot::webhook, Whatsapp_bot::webhook, Telegram_bot::webhook, Webchat::send/poll),
 | stored on the CI superobject as $CI->mb_request_id for the life of that PHP request, and
 | forwarded to internal handlers through mb_internal_headers()'s X-MB-Request-Id header so the
 | receiving side can call request_id_set($forwarded_value) and reuse the SAME id rather than
 | minting a new one. Writers (ai_usage_helper.php, ai_failure_helper.php, Automation_gateway,
 | event_helper.php) read it back with request_id_get() and store it only when the column
 | exists, so this file needs no schema of its own.
 |
 | Never throws: a request id is diagnostics, not a feature. If anything here fails, the caller
 | gets null/empty and continues exactly as before this task.
 */

if (!function_exists('request_id_get')) {
    /** @return string|null the current request's id, or null if none has been set yet. */
    function request_id_get()
    {
        try {
            $CI =& get_instance();
            return (isset($CI->mb_request_id) && $CI->mb_request_id !== '') ? (string) $CI->mb_request_id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('request_id_new')) {
    /** UUID-v4-style id (same shape used elsewhere for opaque identifiers in this codebase). */
    function request_id_new()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('request_id_set')) {
    /**
     * Set (or generate) the current request's id on the CI superobject.
     *
     * @param  string|null $id  when given (e.g. forwarded via X-MB-Request-Id from an internal
     *                          call), reuse it instead of generating a new one, so a webhook's
     *                          request id stays the same through internal forwards.
     * @return string the id now set on $CI->mb_request_id.
     */
    function request_id_set($id = null)
    {
        // Only accept the exact UUID shape request_id_new() produces: the request_id columns are
        // VARCHAR(36), and a forwarded header is untrusted input, so anything else (empty,
        // oversized, non-hex, control characters) gets a fresh id instead.
        $id = is_string($id) ? $id : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id)) $id = request_id_new();
        try {
            $CI =& get_instance();
            $CI->mb_request_id = $id;
        } catch (Throwable $e) { /* diagnostics only, never break the caller */ }
        return $id;
    }
}
