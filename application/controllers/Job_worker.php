<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Generic job worker (remaining-work Task 11 / B3, brief §42). CLI ONLY.
 *
 *   php index.php job_worker run [limit]
 *
 * Claims due `jobs` rows (job_claim(), an atomic UPDATE+SELECT — see job_helper.php), runs the
 * handler registered for each job's `type` (job_handlers()), and reports success/retry/dead
 * letter back. Holds its own MariaDB advisory lock so two ticks never overlap, same pattern as
 * Automation_worker.php and Cron_hub.
 *
 * Over HTTP every method 404s.
 *
 * Scheduled by docker/cron/monkeybot (every minute). That cron file is NOT installed on the
 * host by this branch (OWNER step).
 */
class Job_worker extends Home
{
    const LOCK = 'monkeybot_job_worker';

    public function __construct()
    {
        parent::__construct();
        set_time_limit(0);
        $this->load->helper(array('job', 'ops_health'));
    }

    /** No web surface at all. */
    public function index()
    {
        show_404();
    }

    /**
     * @param int $limit max jobs claimed per tick.
     */
    public function run($limit = 50)
    {
        if (!is_cli()) { show_404(); return; }

        if (!$this->db->table_exists('jobs')) {
            log_message('error', 'job_worker: jobs table missing — run cron_hub migrate');
            echo "jobs table missing\n";
            return;
        }

        $got = $this->db->query("SELECT GET_LOCK('" . self::LOCK . "', 0) AS l")->row();
        if (empty($got) || (int) $got->l !== 1) {
            echo "another job worker tick is running\n";
            return;
        }

        $started = microtime(true);
        $limit = max(1, min(200, (int) $limit));
        $totals = array('claimed' => 0, 'done' => 0, 'retried' => 0, 'dead' => 0);
        $error = null;

        try {
            $handlers = job_handlers();
            $jobs = job_claim($limit);
            $totals['claimed'] = count($jobs);

            foreach ($jobs as $job) {
                $type = $job['type'];
                $payload = job_payload($job);
                try {
                    if (!isset($handlers[$type]) || !is_callable($handlers[$type])) {
                        throw new Exception('no handler registered for job type: ' . $type);
                    }
                    call_user_func($handlers[$type], $payload, $job);
                    job_complete($job['id']);
                    $totals['done']++;
                } catch (Throwable $e) {
                    log_message('error', 'job_worker job ' . $job['id'] . ' (' . $type . '): ' . $e->getMessage());
                    $outcome = job_retry_or_dead_letter($job, $e->getMessage());
                    $totals[$outcome === 'dead' ? 'dead' : 'retried']++;
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            log_message('error', 'job_worker: ' . $e->getMessage());
        }

        $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");

        $detail = "claimed {$totals['claimed']}, done {$totals['done']}, retried {$totals['retried']}, dead {$totals['dead']}";
        ops_health_record('job_worker', $error === null, microtime(true) - $started, $error === null ? $detail : $error);
        echo $detail . "\n";
    }

    /**
     * Diagnostics: what is waiting/dead, oldest first.
     *   php index.php job_worker pending [limit]
     */
    public function pending($limit = 20)
    {
        if (!is_cli()) { show_404(); return; }
        $rows = $this->db->select('id, user_id, type, status, attempts, max_attempts, run_after')
            ->from('jobs')->where_in('status', array('queued', 'running', 'dead'))
            ->order_by('run_after', 'ASC')->limit(max(1, (int) $limit))->get()->result_array();
        echo count($rows) . " job(s) shown\n";
        foreach ($rows as $r) {
            echo "  #{$r['id']} user {$r['user_id']} {$r['type']} {$r['status']} "
               . "attempt {$r['attempts']}/{$r['max_attempts']} run_after {$r['run_after']}\n";
        }
    }
}
