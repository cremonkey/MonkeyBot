<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | General audit log (brief §40).
 |
 | ONE append-only log every feature can write a "who did what to which entity" row to.
 | audit_record() is a single INSERT and it NEVER throws: if the table is missing, the DB
 | is down, or a value will not encode, the row is dropped and a line is logged — the
 | caller's own flow continues untouched. Safe to call from a webhook handler or a hot path.
 |
 | Secrets: any key in before/after whose name matches /token|secret|password|key/i has its
 | value replaced with '***' before it is JSON-encoded, so a snapshot of e.g. a channel
 | credentials row never leaks the credential itself into the log.
 */

if (!function_exists('audit_strip_secrets')) {
    /**
     * Pure. Recursively replace values of secret-looking keys with '***'.
     * @param mixed $value
     * @return mixed
     */
    function audit_strip_secrets($value)
    {
        if (!is_array($value)) return $value;
        $out = array();
        foreach ($value as $k => $v) {
            if (is_string($k) && preg_match('/token|secret|password|key/i', $k)) {
                $out[$k] = '***';
            } elseif (is_array($v)) {
                $out[$k] = audit_strip_secrets($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('audit_encode')) {
    /** Pure. Strip secrets then JSON-encode; NULL in, NULL out. */
    function audit_encode($data)
    {
        if ($data === null) return null;
        $clean = is_array($data) ? audit_strip_secrets($data) : $data;
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }
}

if (!function_exists('audit_log_list')) {
    /**
     * Build and run the audit_log list query used by both the tenant view (own rows) and
     * the admin view (all tenants). Tenant isolation lives HERE, in one place, so every
     * caller gets it for free: pass a real $user_id and only that tenant's rows can ever
     * come back, regardless of what $filters contains. Pass $user_id = null for the
     * all-tenants admin view — callers MUST gate that themselves (admin session check).
     *
     * @param CI_DB_query_builder $db
     * @param int|null $user_id     tenant to scope to, or null for all tenants (admin only)
     * @param array    $filters     entity_type, action, date_from ('Y-m-d'), date_to ('Y-m-d')
     * @param int      $limit
     * @param int      $offset
     * @return array
     */
    function audit_log_list($db, $user_id, array $filters = array(), $limit = 50, $offset = 0)
    {
        $db->from('audit_log');
        if ($user_id !== null) {
            $db->where('user_id', (int) $user_id);
        }
        if (!empty($filters['entity_type'])) {
            $db->where('entity_type', (string) $filters['entity_type']);
        }
        if (!empty($filters['action'])) {
            $db->where('action', (string) $filters['action']);
        }
        if (!empty($filters['date_from'])) {
            $db->where('created_at >=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $db->where('created_at <=', $filters['date_to'] . ' 23:59:59');
        }
        $db->order_by('created_at', 'DESC')->limit((int) $limit, (int) $offset);
        return $db->get()->result_array();
    }
}

if (!function_exists('audit_record')) {
    /**
     * Append one row to audit_log. Never throws, never blocks the caller.
     *
     * @param int         $user_id     owning tenant
     * @param string      $action      short verb, e.g. 'channel.connected'
     * @param string      $entity_type e.g. 'social_account', 'ai_agent', 'automation'
     * @param string|int  $entity_id
     * @param array|null  $before
     * @param array|null  $after
     * @param int|null    $actor_id
     * @param string      $actor_type  one of user|team_member|admin|system|ai
     * @return int inserted id, or 0 when the row could not be stored
     */
    function audit_record($user_id, $action, $entity_type = '', $entity_id = '', $before = null, $after = null, $actor_id = null, $actor_type = 'system')
    {
        try {
            $user_id = (int) $user_id;
            $action = trim((string) $action);
            if ($user_id <= 0 || $action === '') return 0;

            $CI =& get_instance();
            if (!isset($CI->db) || !$CI->db->table_exists('audit_log')) return 0;

            if (!in_array($actor_type, array('user', 'team_member', 'admin', 'system', 'ai'), true)) {
                $actor_type = 'system';
            }
            if ($actor_id === null) {
                $actor_id = isset($CI->session) ? (int) $CI->session->userdata('user_id') : 0;
            }

            $ip = null;
            if (isset($CI->input) && method_exists($CI->input, 'ip_address')) {
                $ip = mb_substr((string) $CI->input->ip_address(), 0, 45);
            }

            $CI->db->insert('audit_log', array(
                'user_id'     => $user_id,
                'actor_id'    => (int) $actor_id,
                'actor_type'  => $actor_type,
                'action'      => mb_substr($action, 0, 64),
                'entity_type' => mb_substr((string) $entity_type, 0, 40),
                'entity_id'   => mb_substr((string) $entity_id, 0, 64),
                'before_json' => audit_encode($before),
                'after_json'  => audit_encode($after),
                'ip'          => $ip,
                'created_at'  => date('Y-m-d H:i:s'),
            ));
            return (int) $CI->db->insert_id();
        } catch (Throwable $e) {
            log_message('error', 'audit_record(' . $action . '): ' . $e->getMessage());
            return 0;
        }
    }
}
