<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Sequence worker (Phase 10.5, brief §25 + §50 + §51). CLI ONLY.
 *
 *   php index.php sequence_worker run [limit]
 *
 * Separate from Automation_worker on purpose (brief item 3: "extend Automation_worker.php's
 * PATTERN with a SEPARATE new CLI controller — do not edit Automation_worker.php"): a
 * sequence enrollment is not an event, it is a schedule. This worker polls
 * `sequence_enrollments` for rows due (`status = 'active' AND next_run_at <= NOW()`) and
 * advances each one by exactly one step through sequence_advance() (application/helpers/
 * sequence_helper.php), which executes the step via Automation_engine::execute_action() —
 * the SAME dispatcher, SAME channel-policy gate and SAME action implementations an
 * Automation Rule uses. Nothing about what an action does is duplicated here.
 *
 * FEATURE FLAG: gated by the SAME config item as the Automation worker,
 * 'automation_engine_enabled'. A Sequence step firing a message has identical blast-radius
 * to an Automation Engine action — giving it its own flag that could be TRUE while the main
 * one is FALSE would be a safety-flag bypass, not a feature. Unset/FALSE = this worker logs
 * one line and exits without touching an enrollment.
 *
 * Holds its OWN MariaDB advisory lock (separate from the Automation worker's) so the two
 * schedules never block each other, and two sequence_worker ticks never overlap each other.
 *
 * Scheduled by docker/cron/monkeybot (every minute, flock + docker exec, same pattern as
 * automation_worker). NOT installed on the host by this branch.
 */
class Sequence_worker extends Home
{
    const LOCK = 'monkeybot_sequence_worker';

    public function __construct()
    {
        parent::__construct();
        set_time_limit(0);
        $this->load->helper(array('sequence', 'ops_health'));
    }

    /** No web surface at all. */
    public function index()
    {
        show_404();
    }

    /**
     * @param int $limit max enrollments advanced per tick.
     */
    public function run($limit = 200)
    {
        if (!is_cli()) { show_404(); return; }

        if ($this->config->item('automation_engine_enabled') !== true) {
            // Deliberately visible (log_threshold on live is 1) — see Automation_worker for
            // the same reasoning: "why did nothing happen?" must have an answer in the log.
            log_message('error', 'sequence_worker: skipped, automation_engine_enabled is not TRUE');
            echo "automation engine disabled (config automation_engine_enabled)\n";
            return;
        }

        if (!$this->db->table_exists('sequence_enrollments') || !$this->db->table_exists('sequences')) {
            log_message('error', 'sequence_worker: sequence tables missing — run cron_hub migrate');
            echo "sequence tables missing\n";
            return;
        }

        $got = $this->db->query("SELECT GET_LOCK('" . self::LOCK . "', 0) AS l")->row();
        if (empty($got) || (int) $got->l !== 1) {
            echo "another sequence worker tick is running\n";
            return;
        }

        $started = microtime(true);
        $limit = max(1, min(2000, (int) $limit));
        $seen = 0;
        // 2026-09-27 (sequences safety gate): skipped_opt_out / skipped_quiet_hours /
        // skipped_rate_limit are the new, distinct skip reasons (see sequence_helper.php's
        // sequence_advance() doc comment); rescheduled_jitter is not a skip at all — the step
        // WILL send, just on a later tick — see the same doc comment for why it is not
        // claimed/finalized like the others.
        $totals = array(
            'success' => 0, 'skipped' => 0, 'skipped_policy' => 0, 'skipped_stop_condition' => 0,
            'skipped_opt_out' => 0, 'skipped_quiet_hours' => 0, 'skipped_rate_limit' => 0,
            'rescheduled_jitter' => 0, 'failed' => 0, 'duplicate' => 0, 'completed' => 0,
        );
        $error = null;

        try {
            $due = $this->db->select('id')->from('sequence_enrollments')
                ->where('status', 'active')->where('next_run_at IS NOT NULL', null, false)
                ->where('next_run_at <=', date('Y-m-d H:i:s'))
                ->order_by('next_run_at', 'ASC')->limit($limit)->get()->result_array();

            foreach ($due as $row) {
                $seen++;
                try {
                    $r = sequence_advance((int) $row['id']);
                    $k = isset($r['status']) ? $r['status'] : 'failed';
                    $totals[$k] = (isset($totals[$k]) ? $totals[$k] : 0) + 1;
                } catch (Throwable $e) {
                    // one poisoned enrollment must not stall the queue behind it
                    log_message('error', 'sequence_worker enrollment ' . $row['id'] . ': ' . $e->getMessage());
                    $error = 'enrollment ' . $row['id'] . ': ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            log_message('error', 'sequence_worker: ' . $e->getMessage());
        }

        $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");

        $detail = "enrollments {$seen}, ok {$totals['success']}, completed {$totals['completed']}, "
                . "skipped {$totals['skipped']}, policy {$totals['skipped_policy']}, "
                . "stopped {$totals['skipped_stop_condition']}, opt_out {$totals['skipped_opt_out']}, "
                . "quiet_hours {$totals['skipped_quiet_hours']}, rate_limit {$totals['skipped_rate_limit']}, "
                . "jitter {$totals['rescheduled_jitter']}, failed {$totals['failed']}, duplicate {$totals['duplicate']}";
        ops_health_record('sequence_worker', $error === null, microtime(true) - $started, $error === null ? $detail : $error);
        echo $detail . "\n";
    }

    /**
     * Diagnostics: what is due, soonest first.
     *   php index.php sequence_worker pending [limit]
     */
    public function pending($limit = 20)
    {
        if (!is_cli()) { show_404(); return; }
        $rows = $this->db->select('id, user_id, sequence_id, subscriber_id, current_step, next_run_at')
            ->from('sequence_enrollments')->where('status', 'active')
            ->where('next_run_at IS NOT NULL', null, false)
            ->order_by('next_run_at', 'ASC')->limit(max(1, (int) $limit))->get()->result_array();
        echo count($rows) . " active enrollment(s) shown\n";
        foreach ($rows as $r) {
            echo "  #{$r['id']} user {$r['user_id']} sequence {$r['sequence_id']} subscriber {$r['subscriber_id']} step {$r['current_step']} due {$r['next_run_at']}\n";
        }
    }
}
