<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-00 Task E — at-rest encryption for API secrets (OpenAI key, provider tokens...).
 |
 | SAFETY: encryption only activates when a STRONG encryption_key is configured.
 | While the key is the framework default/weak ('12345'), secret_encrypt() is a
 | no-op that returns plaintext, so wiring these helpers in is safe TODAY and starts
 | protecting data automatically once a strong key is set (see docs/SECURITY-TASK-E.md).
 | secret_decrypt() always transparently handles both encrypted ('enc::') and plain values.
 */

if (!function_exists('secret_key_is_strong')) {
    function secret_key_is_strong()
    {
        $CI =& get_instance();
        $key = (string) $CI->config->item('encryption_key');
        return $key !== '' && $key !== '12345' && strlen($key) >= 16;
    }
}

if (!function_exists('secret_encrypt')) {
    function secret_encrypt($plain)
    {
        if ($plain === null || $plain === '') return $plain;
        if (strpos((string) $plain, 'enc::') === 0) return $plain; // already encrypted
        if (!secret_key_is_strong()) return $plain; // weak key: store as-is, no false sense of security
        $CI =& get_instance();
        $CI->load->library('encryption');
        return 'enc::' . $CI->encryption->encrypt($plain);
    }
}

if (!function_exists('secret_decrypt')) {
    function secret_decrypt($stored)
    {
        if ($stored === null || $stored === '') return $stored;
        if (strpos((string) $stored, 'enc::') !== 0) return $stored; // plaintext / legacy
        $CI =& get_instance();
        $CI->load->library('encryption');
        $plain = $CI->encryption->decrypt(substr($stored, 5));
        return $plain === false ? '' : $plain;
    }
}

if (!function_exists('secret_mask')) {
    // For display: show only last 4 chars of a secret.
    function secret_mask($plain)
    {
        $plain = (string) $plain;
        $len = strlen($plain);
        if ($len === 0) return '';
        if ($len <= 4) return str_repeat('*', $len);
        return str_repeat('*', min(20, $len - 4)) . substr($plain, -4);
    }
}
