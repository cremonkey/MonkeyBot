<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Agency Control Center accuracy (Phase 11 / brief §31, task A5).
 *
 * Pulls the per-tenant numbers Agency::index() needs, each with ONE grouped query
 * (no N+1 across clients), guarded by table_exists()/field_exists() so the page still
 * renders before a table/column exists. Kept as free functions (not controller methods)
 * so tests/agency/run.php can exercise them against a fake query-builder object with no
 * CodeIgniter bootstrap, no DB, no network.
 */

if (!function_exists('agency_health_table_ready')) {
    /**
     * table_exists()/field_exists() check, cached per (table, columns) for the life of
     * the request — mirrors the `static $has = null` pattern in Inbox.php.
     */
    function agency_health_table_ready($db, $table, $cols = array())
    {
        static $cache = array();
        // Keyed by the $db instance too (not just table+cols): production only ever has one
        // $this->db per request, so this behaves exactly like the rest of the codebase's
        // `static $has = null` guards; it just keeps unrelated fake DBs from bleeding into
        // each other under test.
        $key = spl_object_hash($db) . '|' . $table . '|' . implode(',', $cols);
        if (!array_key_exists($key, $cache)) {
            $ok = $db->table_exists($table);
            if ($ok) {
                foreach ($cols as $c) {
                    if (!$db->field_exists($c, $table)) { $ok = false; break; }
                }
            }
            $cache[$key] = $ok;
        }
        return $cache[$key];
    }
}

if (!function_exists('agency_health_waiting_by_user')) {
    /**
     * Brief A5: "waiting" = conversations currently parked for a human, per client.
     * @return array user_id => count
     */
    function agency_health_waiting_by_user($db)
    {
        if (!agency_health_table_ready($db, 'conversations', array('user_id', 'handoff_state'))) return array();
        $out = array();
        foreach ($db->select('user_id, COUNT(*) c')->from('conversations')
            ->where('handoff_state', 'WAITING_FOR_HUMAN')->group_by('user_id')->get()->result_array() as $r) {
            $out[(int) $r['user_id']] = (int) $r['c'];
        }
        return $out;
    }
}

if (!function_exists('agency_health_crm_push_by_user')) {
    /**
     * 8xCRM push queue (crm_external_log): pending/failed counts + last failure time, per client.
     * @return array user_id => array('pending' => int, 'failed' => int, 'last_failure' => string|null)
     */
    function agency_health_crm_push_by_user($db)
    {
        if (!agency_health_table_ready($db, 'crm_external_log', array('user_id', 'status', 'created_at'))) return array();
        $out = array();
        $get = function ($u) use (&$out) {
            if (!isset($out[$u])) $out[$u] = array('pending' => 0, 'failed' => 0, 'last_failure' => null);
            return $u;
        };
        foreach ($db->select('user_id, status, COUNT(*) c')->from('crm_external_log')
            ->where_in('status', array('pending', 'failed'))->group_by('user_id, status')->get()->result_array() as $r) {
            $u = (int) $r['user_id'];
            $get($u);
            $out[$u][$r['status']] = (int) $r['c'];
        }
        foreach ($db->select('user_id, MAX(created_at) m')->from('crm_external_log')
            ->where('status', 'failed')->group_by('user_id')->get()->result_array() as $r) {
            $u = (int) $r['user_id'];
            $get($u);
            $out[$u]['last_failure'] = $r['m'];
        }
        return $out;
    }
}

if (!function_exists('agency_health_sheets_failures_by_user')) {
    /**
     * Sheets sync (crm_sheet_config — one config row per client, not a per-lead queue;
     * see crm_sheet_helper.php crm_sheet_store_error()/crm_sheet_get_config()).
     * @return array user_id => array('failing' => bool, 'last_error' => string|null, 'last_synced_at' => string|null)
     */
    function agency_health_sheets_failures_by_user($db)
    {
        if (!agency_health_table_ready($db, 'crm_sheet_config', array('user_id', 'status', 'last_error', 'last_synced_at'))) return array();
        $out = array();
        foreach ($db->select('user_id, last_error, last_synced_at')->from('crm_sheet_config')
            ->where('status', '1')->get()->result_array() as $r) {
            $out[(int) $r['user_id']] = array(
                'failing' => !empty($r['last_error']),
                'last_error' => $r['last_error'],
                'last_synced_at' => $r['last_synced_at'],
            );
        }
        return $out;
    }
}

if (!function_exists('agency_health_last_inbound_by_platform')) {
    /**
     * Last webhook seen per channel: MAX(livechat_messages.conversation_time) per
     * user_id + platform, customer messages only.
     * @return array user_id => array(platform => datetime string)
     */
    function agency_health_last_inbound_by_platform($db)
    {
        if (!agency_health_table_ready($db, 'livechat_messages', array('user_id', 'platform', 'sender', 'conversation_time'))) return array();
        $out = array();
        foreach ($db->select('user_id, platform, MAX(conversation_time) m')->from('livechat_messages')
            ->where('sender', 'user')->group_by('user_id, platform')->get()->result_array() as $r) {
            $u = (int) $r['user_id'];
            if (!isset($out[$u])) $out[$u] = array();
            $out[$u][$r['platform']] = $r['m'];
        }
        return $out;
    }
}
