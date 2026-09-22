<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Operational health state (Phase 0, 7.1): last run of each scheduled job, how long it
 | took, what it did, and the last failure. Stored as JSON in application/cache (a
 | persistent volume, denied by nginx) so it needs no schema change and survives restarts.
 | Read by /health (public, minimal) and system_health (admin page).
 */

if (!function_exists('ops_health_file')) {
    function ops_health_file()
    {
        return APPPATH . 'cache/ops_health.json';
    }
}

if (!function_exists('ops_health_read')) {
    function ops_health_read()
    {
        $raw = @file_get_contents(ops_health_file());
        $d = json_decode($raw ?: '{}', true);
        return is_array($d) ? $d : array();
    }
}

if (!function_exists('ops_health_record')) {
    /**
     * @param string $job     e.g. 'cron_hub'
     * @param bool   $ok
     * @param float  $seconds duration
     * @param mixed  $detail  job output (success) or error message (failure)
     */
    function ops_health_record($job, $ok, $seconds, $detail)
    {
        try {
            $fh = @fopen(ops_health_file(), 'c+');
            if (!$fh) return;
            if (flock($fh, LOCK_EX)) {
                $d = json_decode(stream_get_contents($fh) ?: '{}', true);
                if (!is_array($d)) $d = array();
                $j = isset($d[$job]) && is_array($d[$job]) ? $d[$job] : array();
                $now = date('c');
                $j['last_run_at'] = $now;
                $j['last_duration_s'] = round((float) $seconds, 2);
                $j['runs'] = (isset($j['runs']) ? (int) $j['runs'] : 0) + 1;
                if ($ok) {
                    $j['last_success_at'] = $now;
                    $j['last_result'] = $detail;
                    $j['consecutive_failures'] = 0;
                } else {
                    $j['last_failure_at'] = $now;
                    $j['last_error'] = mb_substr((string) $detail, 0, 500);
                    $j['failures'] = (isset($j['failures']) ? (int) $j['failures'] : 0) + 1;
                    $j['consecutive_failures'] = (isset($j['consecutive_failures']) ? (int) $j['consecutive_failures'] : 0) + 1;
                }
                $d[$job] = $j;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                fflush($fh);
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        } catch (Exception $e) {
            // health bookkeeping must never break the job itself
        }
    }
}

if (!function_exists('ops_job_age_seconds')) {
    /** Seconds since the job's last success, or null if it never succeeded. */
    function ops_job_age_seconds($job)
    {
        $d = ops_health_read();
        if (empty($d[$job]['last_success_at'])) return null;
        $t = strtotime($d[$job]['last_success_at']);
        return $t ? max(0, time() - $t) : null;
    }
}
