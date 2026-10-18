<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Webhook authenticity for the Meta pipeline (Phase 0, 7.3).
 |
 | Two checks:
 |   1. Meta -> home/central_webhook_callback: X-Hub-Signature-256 = HMAC-SHA256(raw body,
 |      app secret) for one of the configured Facebook apps (facebook_rx_config.api_secret).
 |   2. central -> internal handlers (messenger_bot/webhook_callback_main,
 |      comment_automation/webhook_callback_main, instagram_reply/webhook_callback):
 |      X-MB-Internal = HMAC-SHA256(response_raw, encryption_key). Those handlers are public
 |      URLs, so without this anyone can POST a forged response_raw.
 |
 | Each check has a mode, read from config (defaults shown):
 |   meta_webhook_signature_mode   'log'   ('off' | 'log' | 'enforce')
 |   internal_webhook_mode         'log'   ('off' | 'log' | 'enforce')
 | 'log' never rejects; it counts outcomes in application/cache/webhook_security.json so we
 | can see real traffic verifies before switching to 'enforce'. Enforcing blind could stop
 | every FB/IG reply if an app secret were wrong.
 */

if (!function_exists('webhook_sec_mode')) {
    function webhook_sec_mode($key)
    {
        $CI =& get_instance();
        $m = (string) $CI->config->item($key);
        return in_array($m, array('off', 'log', 'enforce'), true) ? $m : 'log';
    }
}

if (!function_exists('webhook_sec_record')) {
    /** Count an outcome per check. Never throws: stats must not break message delivery. */
    function webhook_sec_record($check, $result)
    {
        try {
            $file = APPPATH . 'cache/webhook_security.json';
            $fh = @fopen($file, 'c+');
            if (!$fh) return;
            if (flock($fh, LOCK_EX)) {
                $raw = stream_get_contents($fh);
                $d = json_decode($raw ?: '{}', true);
                if (!is_array($d)) $d = array();
                if (!isset($d[$check])) $d[$check] = array();
                $d[$check][$result] = (isset($d[$check][$result]) ? (int) $d[$check][$result] : 0) + 1;
                $d[$check]['last_' . $result] = date('c');
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($d, JSON_PRETTY_PRINT));
                fflush($fh);
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        } catch (Exception $e) {
            // ignore
        }
    }
}

if (!function_exists('meta_signature_status')) {
    /**
     * @return string valid | invalid | missing | no_secret
     */
    function meta_signature_status($raw_body)
    {
        $header = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
        if ($header === '' || strpos($header, 'sha256=') !== 0 || $raw_body === '') return 'missing';
        $given = substr($header, 7);

        $CI =& get_instance();
        if (!function_exists('secret_decrypt')) $CI->load->helper('secret');
        $rows = $CI->db->select('api_secret')->from('facebook_rx_config')
            ->where('deleted', '0')->get()->result_array();
        // Instagram Login webhooks are signed with the Instagram app secret (ig_login_helper.php)
        try {
            if ($CI->db->table_exists('ig_login_config')) {
                foreach ($CI->db->select('app_secret_enc AS api_secret')->from('ig_login_config')->get()->result_array() as $ig) $rows[] = $ig;
            }
        } catch (\Throwable $e) {}
        $any = false;
        foreach ($rows as $r) {
            $secret = trim((string) secret_decrypt((string) $r['api_secret']));
            if ($secret === '') continue;
            $any = true;
            if (hash_equals(hash_hmac('sha256', $raw_body, $secret), $given)) return 'valid';
        }
        return $any ? 'invalid' : 'no_secret';
    }
}

if (!function_exists('meta_signature_gate')) {
    /** Returns false only when the mode is 'enforce' and the request is not verifiably from Meta. */
    function meta_signature_gate($raw_body)
    {
        $mode = webhook_sec_mode('meta_webhook_signature_mode');
        if ($mode === 'off') return true;
        $status = meta_signature_status($raw_body);
        webhook_sec_record('meta_signature', $status);
        if ($status !== 'valid' && $mode === 'log') {
            log_message('error', 'meta webhook signature ' . $status . ' (log mode, not rejected)');
        }
        return $mode !== 'enforce' || $status === 'valid';
    }
}

if (!function_exists('mb_internal_sign')) {
    function mb_internal_sign($response_raw)
    {
        $CI =& get_instance();
        return hash_hmac('sha256', (string) $response_raw, (string) $CI->config->item('encryption_key'));
    }
}

if (!function_exists('mb_internal_headers')) {
    /** Headers an internal forwarder adds to a response_raw POST. */
    function mb_internal_headers($response_raw)
    {
        $headers = array('X-MB-Internal: ' . mb_internal_sign($response_raw));
        // Task 13 (brief 39/51): carry the webhook's request id along so the internal handler
        // reuses it (request_id_set()) instead of minting a new one. Does not touch the HMAC
        // signing above; this is a separate, unsigned header purely for correlation.
        if (function_exists('request_id_get')) {
            $rid = request_id_get();
            if ($rid !== null && $rid !== '') $headers[] = 'X-MB-Request-Id: ' . $rid;
        }
        return $headers;
    }
}

if (!function_exists('mb_internal_gate')) {
    /** For the internal handlers. Returns false only in 'enforce' mode with a bad/missing signature. */
    function mb_internal_gate($response_raw)
    {
        $mode = webhook_sec_mode('internal_webhook_mode');
        // Task 13: read the forwarded request id, if any, before (and regardless of) the HMAC
        // check below, so downstream work in this internal request reuses the caller's id. Does
        // not change the HMAC signing/verification logic itself.
        if (function_exists('request_id_set')) {
            $forwarded = isset($_SERVER['HTTP_X_MB_REQUEST_ID']) ? (string) $_SERVER['HTTP_X_MB_REQUEST_ID'] : null;
            if ($forwarded !== null && $forwarded !== '') request_id_set($forwarded);
        }
        if ($mode === 'off') return true;
        $given = isset($_SERVER['HTTP_X_MB_INTERNAL']) ? (string) $_SERVER['HTTP_X_MB_INTERNAL'] : '';
        if ($given === '') $status = 'missing';
        else $status = hash_equals(mb_internal_sign($response_raw), $given) ? 'valid' : 'invalid';
        webhook_sec_record('internal_forward', $status);
        if ($status !== 'valid' && $mode === 'log') {
            log_message('error', 'internal webhook forward ' . $status . ' (log mode, not rejected)');
        }
        return $mode !== 'enforce' || $status === 'valid';
    }
}
