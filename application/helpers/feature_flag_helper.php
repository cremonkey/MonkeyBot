<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Per-tenant feature flags (Task 10 / brief B2, §50).
 *
 * Precedence for feature_enabled($flag, $uid):
 *   1. A row in `feature_flags` for (user_id, flag) — tenant override wins, TINYINT 1/0.
 *   2. Otherwise the existing global config item: $this->config->item($flag), where
 *      TRUE, '1' or 1 all count as "on" — the exact semantics every flag in this codebase
 *      already reads with (e.g. automation_engine_enabled, inbox_poll_enabled).
 *   3. Otherwise FALSE.
 *
 * This does NOT change what any existing global flag does: the table starts empty, nothing
 * seeds it, and until an admin sets an explicit per-tenant override every call falls straight
 * through to the same config item it always read.
 *
 * Guarded by table_exists() (cached per request, mirrors Inbox.php's `static $has` pattern) so
 * this works before the migration (assets/backup_db/migrations/2026-10-03b-feature-flags.sql)
 * has run — it just behaves as if no tenant ever has an override.
 *
 * Kept as free functions (not controller methods) so tests/feature_flags/run.php can exercise
 * them against a fake query-builder / fake CI object with no CodeIgniter bootstrap, no DB, no
 * network.
 */

if (!function_exists('feature_flags_table_ready')) {
    /** table_exists() check, cached per ($db instance) for the life of the request. */
    function feature_flags_table_ready($db)
    {
        static $cache = array();
        $key = spl_object_hash($db);
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = (bool) $db->table_exists('feature_flags');
        }
        return $cache[$key];
    }
}

if (!function_exists('feature_flag_truthy')) {
    /** TRUE/'1'/1 = on, exactly like the config-item convention already used everywhere. */
    function feature_flag_truthy($v)
    {
        return $v === true || $v === '1' || $v === 1;
    }
}

if (!function_exists('feature_enabled')) {
    /**
     * @param CI_DB_query_builder $db     $this->db — a real CI DB object or a test fake with
     *                                    table_exists()/select()/from()/where()/get().
     * @param CI_Config           $config $this->config — a real CI config object or a test
     *                                    fake with item($key).
     * @param string              $flag   config-item / flag name, e.g. 'tiktok_dm_enabled'.
     * @param int                 $uid    tenant user_id.
     * @return bool
     */
    function feature_enabled($db, $config, $flag, $uid)
    {
        static $cache = array();
        $flag = (string) $flag;
        $uid = (int) $uid;
        $cache_key = spl_object_hash($db) . '|' . $uid . '|' . $flag;
        if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

        $result = null;
        if (feature_flags_table_ready($db)) {
            $row = $db->select('enabled')->from('feature_flags')
                ->where('user_id', $uid)->where('flag', $flag)
                ->get()->row_array();
            if ($row !== null && $row !== array()) $result = ((int) $row['enabled']) === 1;
        }
        if ($result === null) {
            $result = feature_flag_truthy($config->item($flag));
        }

        $cache[$cache_key] = $result;
        return $result;
    }
}

if (!function_exists('feature_flag_set')) {
    /**
     * Admin write: set (or clear) the tenant override for one flag. Called only from
     * Agency_flags::toggle_flag() (CSRF-checked, admin-gated, client id validated there).
     *
     * @param bool|null $enabled TRUE/FALSE sets the override; NULL deletes the row so the
     *                           tenant falls back to the global config item again.
     * @return bool TRUE on success, FALSE if the table does not exist yet (migration pending).
     */
    function feature_flag_set($db, $uid, $flag, $enabled)
    {
        if (!feature_flags_table_ready($db)) return false;
        $uid = (int) $uid;
        $flag = (string) $flag;

        if ($enabled === null) {
            $db->where('user_id', $uid)->where('flag', $flag)->delete('feature_flags');
            return true;
        }

        $existing = $db->select('user_id')->from('feature_flags')
            ->where('user_id', $uid)->where('flag', $flag)->get()->row_array();
        $data = array('enabled' => $enabled ? 1 : 0);
        if ($existing !== null && $existing !== array()) {
            $db->where('user_id', $uid)->where('flag', $flag)->update('feature_flags', $data);
        } else {
            $data['user_id'] = $uid;
            $data['flag'] = $flag;
            $db->insert('feature_flags', $data);
        }
        return true;
    }
}

if (!function_exists('feature_flags_for_user')) {
    /**
     * All tenant overrides for one user, keyed by flag name => bool. Used by the admin UI to
     * show current state. Empty array before the migration runs.
     */
    function feature_flags_for_user($db, $uid)
    {
        if (!feature_flags_table_ready($db)) return array();
        $out = array();
        foreach ($db->select('flag, enabled')->from('feature_flags')
            ->where('user_id', (int) $uid)->get()->result_array() as $r) {
            $out[$r['flag']] = ((int) $r['enabled']) === 1;
        }
        return $out;
    }
}

if (!function_exists('feature_flags_all_by_user')) {
    /**
     * All tenant overrides for every user, in ONE grouped query (no N+1 across clients — same
     * shape as agency_health_helper.php's per-tenant aggregates). @return array
     *   user_id => array(flag => bool)
     */
    function feature_flags_all_by_user($db)
    {
        if (!feature_flags_table_ready($db)) return array();
        $out = array();
        foreach ($db->select('user_id, flag, enabled')->from('feature_flags')->get()->result_array() as $r) {
            $out[(int) $r['user_id']][$r['flag']] = ((int) $r['enabled']) === 1;
        }
        return $out;
    }
}

if (!function_exists('feature_flags_known')) {
    /**
     * Suggested flag names for the admin UI's datalist (existing global config->item() flags
     * already read by the codebase). Purely a UI convenience — feature_enabled()/feature_flag_set()
     * accept any flag name, so an admin can still type one not in this list.
     */
    function feature_flags_known()
    {
        return array(
            'automation_engine_enabled',
            'facebook_poster_botenabled_pages',
            'inbox_poll_enabled',
            'tiktok_dm_enabled',
        );
    }
}
