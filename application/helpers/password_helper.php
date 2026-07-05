<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-00 Task A — centralized password hashing with backward-compatible MD5.
 | Existing accounts were stored as md5(password). New/updated passwords use
 | password_hash(). Verification accepts both; legacy md5 rows are transparently
 | upgraded on the next successful login (see pw_needs_rehash usage in Home::login).
 */

if (!function_exists('pw_hash')) {
    // Produce a storage hash for a new/changed password.
    function pw_hash($plain)
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }
}

if (!function_exists('pw_is_md5')) {
    function pw_is_md5($stored)
    {
        return is_string($stored) && strlen($stored) === 32 && ctype_xdigit($stored);
    }
}

if (!function_exists('pw_verify')) {
    // Verify a plaintext password against a stored hash (password_hash OR legacy md5).
    function pw_verify($plain, $stored)
    {
        if ($stored === null || $stored === '') return false;
        if (pw_is_md5($stored)) {
            return hash_equals($stored, md5($plain));
        }
        return password_verify($plain, $stored);
    }
}

if (!function_exists('pw_needs_rehash')) {
    // True when the stored value should be re-saved as a modern hash (legacy md5 or outdated algo).
    function pw_needs_rehash($stored)
    {
        if (pw_is_md5($stored)) return true;
        return password_needs_rehash($stored, PASSWORD_DEFAULT);
    }
}
