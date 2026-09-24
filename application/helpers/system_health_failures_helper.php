<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * System Health > "Failures (24h)" panel (remaining-work Task 13, brief 39/51).
 *
 * Pulls the last-24h failure counts System_health::index() shows, each guarded by
 * table_exists() (and field_exists() where the time column isn't guaranteed) so the page
 * still renders on a database that hasn't run every migration yet, and skips a sub-panel
 * entirely when its table doesn't exist in this codebase at all (there is no `jobs` table).
 * Kept as a free function (not a controller method) so tests/request_id/run.php can exercise
 * it against a fake query-builder object with no CodeIgniter bootstrap, no DB, no network —
 * mirrors agency_health_helper.php's shape.
 *
 * @return array<string, array{label:string, count:int, link:string}> panel key => data.
 *         Keys present only when their source table exists.
 */

if (!function_exists('system_health_failures_24h')) {
    function system_health_failures_24h($db)
    {
        $out = array();
        $since = date('Y-m-d H:i:s', time() - 86400);

        // AI failures + tool failures: ai_failure_log.kind ('provider' | 'tool').
        if ($db->table_exists('ai_failure_log')) {
            $ai = 0; $tool = 0;
            foreach ($db->select('kind, COUNT(*) c')->from('ai_failure_log')
                ->where('created_at >=', $since)->group_by('kind')->get()->result_array() as $r) {
                if ($r['kind'] === 'tool') $tool = (int) $r['c'];
                else $ai += (int) $r['c'];
            }
            // No dedicated admin page reads ai_failure_log yet, so no link is offered (never a
            // dead/invented route) — the count itself is the signal.
            $out['ai_failures']   = array('label' => 'AI failures',   'count' => $ai,   'link' => '');
            $out['tool_failures'] = array('label' => 'Tool failures', 'count' => $tool, 'link' => '');
        }

        // Send failures: messenger_bot_reply_error_log.error_time (no created_at column).
        if ($db->table_exists('messenger_bot_reply_error_log') && $db->field_exists('error_time', 'messenger_bot_reply_error_log')) {
            $n = (int) $db->from('messenger_bot_reply_error_log')->where('error_time >=', $since)->count_all_results();
            $out['send_failures'] = array('label' => 'Send failures', 'count' => $n, 'link' => 'messenger_bot');
        }

        // CRM push failures: crm_external_log.status = 'failed' (2026-07-17-external-crm-queue-fix.sql).
        if ($db->table_exists('crm_external_log') && $db->field_exists('status', 'crm_external_log')) {
            $n = (int) $db->from('crm_external_log')->where('status', 'failed')->where('created_at >=', $since)->count_all_results();
            $out['crm_push_failures'] = array('label' => 'CRM push failures', 'count' => $n, 'link' => 'crm');
        }

        // Job failures: no `jobs` table exists in this codebase — skip rather than fake it.
        if ($db->table_exists('jobs')) {
            $n = (int) $db->from('jobs')->where('status', 'failed')->where('created_at >=', $since)->count_all_results();
            $out['job_failures'] = array('label' => 'Job failures', 'count' => $n, 'link' => '');
        }

        return $out;
    }
}
