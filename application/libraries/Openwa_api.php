<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * OpenWA HTTP client — self-hosted WhatsApp API gateway (wa.cremonkey.com).
 * Auth: X-API-Key header. chatId format: {digits}@c.us
 */
class Openwa_api
{
    private function request($method, $base_url, $api_key, $path, $payload = null)
    {
        $url = rtrim($base_url, '/') . '/api' . $path;
        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $api_key,
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        // Keep under webhook processing budget; hung WhatsApp sessions must fail fast
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $method = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload === null ? '{}' : json_encode($payload));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return array('_http' => 0, '_error' => $err ?: 'curl failed');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) $decoded = array('raw' => $raw);
        $decoded['_http'] = $code;
        return $decoded;
    }

    /** Normalize phone / jid → OpenWA chatId */
    public function to_chat_id($phone_or_jid)
    {
        $v = trim((string) $phone_or_jid);
        if ($v === '') return '';

        // Keep group / LID JIDs intact (WhatsApp Web LID migration)
        if (stripos($v, '@g.us') !== false || stripos($v, '@lid') !== false) {
            return $v;
        }

        $digits = preg_replace('/\D+/', '', $v);
        if ($digits === '') return '';

        // Egypt local mobiles: 01xxxxxxxxx → 201xxxxxxxxx
        if (preg_match('/^01[0125]\d{8}$/', $digits)) {
            $digits = '2' . $digits;
        }
        // Common mistake: leading 0 before country code (0201…)
        if (preg_match('/^0(20\d+)$/', $digits, $m)) {
            $digits = $m[1];
        }

        // LID digits (typically 14+) — even when wrongly stored as @c.us
        // Do not exclude leading "1" (many LIDs start with 1; US phones are ~11 digits).
        if (strlen($digits) >= 14) {
            return $digits . '@lid';
        }

        return $digits . '@c.us';
    }

    /** Strip server suffix → subscriber key (keeps LID / phone digits) */
    public function to_subscribe_id($chat_id)
    {
        $v = trim((string) $chat_id);
        if ($v === '') return '';
        if (($pos = strpos($v, '@')) !== false) $v = substr($v, 0, $pos);
        return preg_replace('/\D+/', '', $v) ?: $v;
    }

    /** True when chat id is a Linked ID (not a phone number) */
    public function is_lid($chat_id)
    {
        $v = trim((string) $chat_id);
        if (stripos($v, '@lid') !== false) return true;
        $digits = preg_replace('/\D+/', '', $v);
        return $digits !== '' && strlen($digits) >= 14;
    }

    public function list_sessions($base_url, $api_key)
    {
        return $this->request('GET', $base_url, $api_key, '/sessions');
    }

    public function get_session($base_url, $api_key, $session_id)
    {
        return $this->request('GET', $base_url, $api_key, '/sessions/' . rawurlencode($session_id));
    }

    public function send_text($base_url, $api_key, $session_id, $to, $text)
    {
        $chat_id = $this->to_chat_id($to);
        return $this->request('POST', $base_url, $api_key, '/sessions/' . rawurlencode($session_id) . '/messages/send-text', array(
            'chatId' => $chat_id,
            'text' => $text,
        ));
    }

    public function send_image($base_url, $api_key, $session_id, $to, $url, $caption = '')
    {
        $payload = array('chatId' => $this->to_chat_id($to), 'url' => $url);
        if ($caption !== '') $payload['caption'] = $caption;
        return $this->request('POST', $base_url, $api_key, '/sessions/' . rawurlencode($session_id) . '/messages/send-image', $payload);
    }

    public function create_webhook($base_url, $api_key, $session_id, $url, $events = null, $secret = null)
    {
        if ($events === null) $events = array('message.received');
        $payload = array('url' => $url, 'events' => $events);
        if ($secret) $payload['secret'] = $secret;
        return $this->request('POST', $base_url, $api_key, '/sessions/' . rawurlencode($session_id) . '/webhooks', $payload);
    }

    public function delete_webhook($base_url, $api_key, $session_id, $webhook_id)
    {
        return $this->request('DELETE', $base_url, $api_key, '/sessions/' . rawurlencode($session_id) . '/webhooks/' . rawurlencode($webhook_id));
    }

    public function list_webhooks($base_url, $api_key, $session_id)
    {
        return $this->request('GET', $base_url, $api_key, '/sessions/' . rawurlencode($session_id) . '/webhooks');
    }

    /** Verify X-OpenWA-Signature: sha256=HMAC(body, secret) */
    public function verify_signature($raw_body, $signature_header, $secret)
    {
        if ($secret === null || $secret === '') return true; // optional when not configured
        $sig = (string) $signature_header;
        $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $secret);
        return hash_equals($expected, $sig);
    }
}
