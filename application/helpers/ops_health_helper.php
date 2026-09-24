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

if (!function_exists('AUTOMATION_WORKER_STALE_SECONDS')) {
    define('AUTOMATION_WORKER_STALE_SECONDS', 600);   // 10 minutes (task 6 / brief §51)
}

if (!function_exists('automation_workers_status')) {
    /*
     | No silent queueing (task 6 / brief §51). Automation Rules and Sequences both write
     | rows the moment they're saved/enrolled, but nothing is actually SENT until
     | automation_worker / sequence_worker run — which only happens when
     | 'automation_engine_enabled' is TRUE (see Automation_worker.php, Sequence_worker.php).
     | An owner who saves a rule and never sees a banner has no way to know their message
     | queue is silently piling up. This is the single source of truth both the page banner
     | (Automations.php, Sequences.php + their views) and the enroll/publish JSON responses
     | use, so the two can never say different things.
     |
     | @param bool       $engine_enabled  $this->config->item('automation_engine_enabled') === true
     | @param array|null $health          ops_health_read() shape, keyed by job name. Pass an
     |                                    explicit array in tests; null reads the real file
     |                                    (production/controller use).
     | @param int|null   $now             injectable clock for tests; null = time().
     |
     | @return array{
     |   state: 'running'|'paused_by_flag'|'stale',
     |   workers_paused: bool,
     |   reason: string,
     |   message_en: string,
     |   message_ar: string,
     |   stale_jobs: string[],
     | }
     */
    function automation_workers_status($engine_enabled, array $health = null, $now = null)
    {
        $now = $now !== null ? (int) $now : time();
        $jobs = array('automation_worker', 'sequence_worker');

        $message_en = 'Automations are paused on this server — nothing will be sent.';
        $message_ar = 'الأتمتة متوقفة على السيرفر دلوقتي — مفيش هيتبعت.';

        if ($engine_enabled !== true) {
            return array(
                'state' => 'paused_by_flag',
                'workers_paused' => true,
                'reason' => 'automation_engine_enabled is not TRUE',
                'message_en' => $message_en,
                'message_ar' => $message_ar,
                'stale_jobs' => $jobs,
            );
        }

        if ($health === null) $health = ops_health_read();

        $stale_jobs = array();
        foreach ($jobs as $job) {
            $last_success = isset($health[$job]['last_success_at']) ? $health[$job]['last_success_at'] : null;
            $age = null;
            if ($last_success) {
                $t = strtotime($last_success);
                if ($t) $age = max(0, $now - $t);
            }
            if ($age === null || $age > AUTOMATION_WORKER_STALE_SECONDS) $stale_jobs[] = $job;
        }

        if (!empty($stale_jobs)) {
            return array(
                'state' => 'stale',
                'workers_paused' => true,
                'reason' => 'no successful run of ' . implode(', ', $stale_jobs) . ' in the last '
                    . (AUTOMATION_WORKER_STALE_SECONDS / 60) . ' minutes',
                'message_en' => $message_en,
                'message_ar' => $message_ar,
                'stale_jobs' => $stale_jobs,
            );
        }

        return array(
            'state' => 'running',
            'workers_paused' => false,
            'reason' => '',
            'message_en' => '',
            'message_ar' => '',
            'stale_jobs' => array(),
        );
    }
}
