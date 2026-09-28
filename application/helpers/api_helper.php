<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * API v2 (read-only) — Task 28 (C13, brief §37). Bearer-token auth, tenant scoping, JSON
 * envelope, pagination and a simple per-token rate limit, factored out as free functions so
 * Api_v2_controller (application/libraries/Api_v2_controller.php) and
 * tests/api_v2/run.php exercise the exact same logic without a CodeIgniter bootstrap, no DB,
 * no network.
 *
 * Tables: `api_tokens` (id, user_id, token_hash, name, scopes, last_used_at, expires_at,
 * revoked_at, created_at) and `api_rate_limit` (token_id, window_start, request_count) —
 * assets/backup_db/migrations/2026-10-04g-api-tokens.sql. Both guarded by table_exists()
 * (cached per request, same pattern as feature_flag_helper.php / Inbox.php) so this file works
 * before the migration has run: before then, every token is rejected as unauthenticated and
 * rate limiting is a no-op allow (there is nothing to enforce against).
 *
 * The plaintext token is never stored or compared: api_token_hash() sha256's it and every
 * lookup goes by that hash. A bearer token is only ever equal-compared as a hash, never
 * substring-compared, so there's no timing side channel worth hardening further for a
 * read-only internal API.
 */

if (!function_exists('api_tokens_table_ready')) {
    function api_tokens_table_ready($db)
    {
        static $cache = array();
        $key = spl_object_hash($db);
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = (bool) $db->table_exists('api_tokens');
        }
        return $cache[$key];
    }
}

if (!function_exists('api_rate_limit_table_ready')) {
    function api_rate_limit_table_ready($db)
    {
        static $cache = array();
        $key = spl_object_hash($db);
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = (bool) $db->table_exists('api_rate_limit');
        }
        return $cache[$key];
    }
}

if (!function_exists('api_token_hash')) {
    /** sha256 hex of a plaintext bearer token. Never store or compare the plaintext itself. */
    function api_token_hash($plaintext)
    {
        return hash('sha256', (string) $plaintext);
    }
}

if (!function_exists('api_token_generate')) {
    /**
     * Generates a new plaintext token + its hash. The plaintext is returned once (caller shows
     * it to the tenant and discards it); only the hash is meant to be persisted.
     * @return array array('plaintext' => 'mb_...', 'hash' => '<sha256 hex>')
     */
    function api_token_generate()
    {
        $plaintext = 'mb_' . bin2hex(random_bytes(32));
        return array('plaintext' => $plaintext, 'hash' => api_token_hash($plaintext));
    }
}

if (!function_exists('api_bearer_token_from_header')) {
    /** Extracts the token from an 'Authorization: Bearer <token>' header value. '' if absent/malformed. */
    function api_bearer_token_from_header($header_value)
    {
        $header_value = trim((string) $header_value);
        if ($header_value === '') return '';
        if (stripos($header_value, 'Bearer ') !== 0) return '';
        return trim(substr($header_value, 7));
    }
}

if (!function_exists('api_token_lookup')) {
    /** Looks up an api_tokens row by its hash. null when not found or the table doesn't exist yet. */
    function api_token_lookup($db, $token_hash)
    {
        if (!api_tokens_table_ready($db)) return null;
        $row = $db->where('token_hash', $token_hash)->limit(1)->get('api_tokens')->row_array();
        return $row ?: null;
    }
}

if (!function_exists('api_token_state')) {
    /**
     * Pure check of a fetched api_tokens row: 'ok' | 'revoked' | 'expired'. $now is injectable
     * for tests (defaults to time()). Comparisons use strtotime() the same way the rest of this
     * codebase compares stored 'Y-m-d H:i:s' datetimes against "now".
     */
    function api_token_state(array $token_row, $now = null)
    {
        $now = $now === null ? time() : $now;
        if (!empty($token_row['revoked_at'])) return 'revoked';
        if (!empty($token_row['expires_at']) && strtotime($token_row['expires_at']) <= $now) return 'expired';
        return 'ok';
    }
}

if (!function_exists('api_token_authenticate')) {
    /**
     * Full authenticate step for a raw Authorization header value: looks the token up by hash
     * and checks its state. Returns:
     *   array('ok' => true,  'token' => <api_tokens row>)
     *   array('ok' => false, 'reason' => 'missing'|'not_found'|'revoked'|'expired')
     * Never touches last_used_at (that's a write; the caller does it after a successful auth,
     * only when it has a real write-capable $db, so tests can call this with a read-only fake).
     */
    function api_token_authenticate($db, $header_value, $now = null)
    {
        $plaintext = api_bearer_token_from_header($header_value);
        if ($plaintext === '') return array('ok' => false, 'reason' => 'missing');

        $row = api_token_lookup($db, api_token_hash($plaintext));
        if ($row === null) return array('ok' => false, 'reason' => 'not_found');

        $state = api_token_state($row, $now);
        if ($state !== 'ok') return array('ok' => false, 'reason' => $state);

        return array('ok' => true, 'token' => $row);
    }
}

if (!function_exists('api_rate_limit_window')) {
    /** The current one-minute window, truncated to the minute, as 'Y-m-d H:i:00'. */
    function api_rate_limit_window($now = null)
    {
        $now = $now === null ? time() : $now;
        return date('Y-m-d H:i:00', $now);
    }
}

if (!function_exists('api_rate_limit_check')) {
    /**
     * Simple fixed-window per-token rate limit: at most $limit requests per rolling-minute
     * window, counted in `api_rate_limit`. Table-based (brief: "table or cache-based — keep
     * simple"). Fails OPEN (allows the request) when the table doesn't exist yet, i.e. before
     * the migration has run — this is a courtesy limit, not a security control, so "no table
     * yet" must not 500 every API call.
     *
     * @return bool true = allowed, false = over the limit for this window
     */
    function api_rate_limit_check($db, $token_id, $limit = 60, $now = null)
    {
        if (!api_rate_limit_table_ready($db)) return true;

        $window = api_rate_limit_window($now);
        $existing = $db->where('token_id', (int) $token_id)->where('window_start', $window)
            ->limit(1)->get('api_rate_limit')->row_array();

        if (!$existing) {
            $db->insert('api_rate_limit', array(
                'token_id' => (int) $token_id, 'window_start' => $window, 'request_count' => 1,
            ));
            return true;
        }

        if ((int) $existing['request_count'] >= $limit) return false;

        $db->where('token_id', (int) $token_id)->where('window_start', $window)
            ->set('request_count', 'request_count + 1', false)->update('api_rate_limit');
        return true;
    }
}

if (!function_exists('api_pagination_bounds')) {
    /** Clamps (page, per_page) -> array($page, $per_page, $offset). 1-indexed page, same clamp shape as customer_pagination_bounds(). */
    function api_pagination_bounds($page, $per_page, $default = 20, $max = 100)
    {
        $page = (int) $page;
        if ($page < 1) $page = 1;
        $per_page = (int) $per_page;
        if ($per_page <= 0 || $per_page > $max) $per_page = $default;
        return array($page, $per_page, ($page - 1) * $per_page);
    }
}

if (!function_exists('api_pagination_meta')) {
    function api_pagination_meta($page, $per_page, $total)
    {
        $total = (int) $total;
        $per_page = max(1, (int) $per_page);
        return array(
            'page'        => (int) $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $per_page),
        );
    }
}

if (!function_exists('api_envelope')) {
    /** Consistent {data, meta} JSON envelope for every api/v2 response. */
    function api_envelope($data, array $meta = array())
    {
        return array('data' => $data, 'meta' => $meta);
    }
}

if (!function_exists('api_error_envelope')) {
    function api_error_envelope($message, array $meta = array())
    {
        return array('error' => array('message' => (string) $message), 'meta' => $meta);
    }
}

if (!function_exists('api_scope_allows')) {
    /** '*' or a comma list containing $area (case-insensitive) grants access to that api/v2 area. */
    function api_scope_allows($scopes, $area)
    {
        $scopes = trim((string) $scopes);
        if ($scopes === '' || $scopes === '*') return true;
        $list = array_map('trim', explode(',', strtolower($scopes)));
        return in_array(strtolower($area), $list, true);
    }
}
