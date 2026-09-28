<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Teams + team inbox (Task 17, C2, brief §13). Pure / DB-shape logic factored out of
 * Inbox.php so it is unit-testable without a full CodeIgniter bootstrap (tests/teams/run.php),
 * same approach as inbox_helper.php and customer_helper.php: the controller calls these same
 * functions, so a passing test proves the real code path.
 *
 * Tables (migration 2026-10-04b-teams.sql):
 *   teams             (id, user_id, name, created_at)          — tenant-scoped like team_members
 *   team_memberships  (id, team_id, team_member_id)             — team_member_id -> team_members.id
 *   conversations.assigned_team_id  nullable, independent of the existing assigned_user_id
 *
 * Guard every new table with team_tables_ready() (cached per request, like Inbox::labels_ready())
 * so the code works before the migration runs.
 */

if (!function_exists('team_tables_ready')) {
    /** True once teams + team_memberships both exist. Cache the result yourself (static) per request. */
    function team_tables_ready($db)
    {
        return $db->table_exists('teams') && $db->table_exists('team_memberships');
    }
}

if (!function_exists('team_scope_list')) {
    /** Tenant-scoped list of a user's teams. Expects FROM teams already set, or sets it here. */
    function team_scope_list($db, $user_id)
    {
        $db->where('user_id', (int) $user_id);
        return $db;
    }
}

if (!function_exists('team_scope_owner')) {
    /** Tenant + id guard for a single team (rename, delete, membership changes). */
    function team_scope_owner($db, $team_id, $user_id)
    {
        return $db->where('id', (int) $team_id)->where('user_id', (int) $user_id);
    }
}

if (!function_exists('team_normalize_name')) {
    /** A team name as it is stored: tags stripped, whitespace collapsed, trimmed, max 120 chars. */
    function team_normalize_name($name)
    {
        $name = trim(preg_replace('/\s+/', ' ', strip_tags((string) $name)));
        if ($name === '') return null;
        return mb_substr($name, 0, 120);
    }
}

if (!function_exists('team_ids_for_member')) {
    /**
     * Pure. Given the rows of team_memberships (array of ['team_id'=>..,'team_member_id'=>..])
     * already scoped to this tenant's teams, return the distinct team ids that include
     * $team_member_id. Used to build the "My team" conversation filter (assigned_team_id IN (...)).
     */
    function team_ids_for_member(array $membership_rows, $team_member_id)
    {
        $team_member_id = (int) $team_member_id;
        $ids = array();
        foreach ($membership_rows as $row) {
            if ((int) $row['team_member_id'] === $team_member_id) $ids[(int) $row['team_id']] = true;
        }
        return array_values(array_map('intval', array_keys($ids)));
    }
}
