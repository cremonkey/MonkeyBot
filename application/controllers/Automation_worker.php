<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once("Home.php");

/**
 * Automation worker (Phase 10.5, brief 23 + 50 + 51). CLI ONLY.
 *
 *   php index.php automation_worker run [limit]
 *
 * Drains `events` in id order: for each unprocessed event it runs every active rule of that
 * event's tenant whose trigger matches, then stamps processed_at. Over HTTP every method
 * 404s — there is no secret to leak and no reason for the web to reach it.
 *
 * Separate from Cron_hub on purpose: this loop calls out to Meta/WhatsApp/webhooks and can
 * be slow, and it must be able to run every minute without waiting behind the 5-minute
 * sales-automation tick. It holds its OWN MariaDB advisory lock so two ticks never overlap.
 *
 * FEATURE FLAG (brief 50): processing only happens when config item
 * 'automation_engine_enabled' is TRUE. Unset/FALSE = the worker logs one line and exits
 * without touching an event. Emitting events is NOT flagged, so when the owner switches the
 * engine on the history is already there. Note that events emitted while the flag was off
 * are still unprocessed and WILL be picked up on the first enabled run — see
 * docs/automations/engine.md ("Turning it on") for the catch-up procedure.
 *
 * Scheduled by docker/cron/monkeybot (every minute, flock + docker exec, same pattern as
 * cron_hub). That cron file is NOT installed on the host by this branch.
 */
class Automation_worker extends Home
{
    const LOCK = 'monkeybot_automation_worker';

    public function __construct()
    {
        parent::__construct();
        set_time_limit(0);
        $this->load->helper(array('event', 'ops_health'));
    }

    /** No web surface at all. */
    public function index()
    {
        show_404();
    }

    /**
     * @param int $limit max events per tick. 200 at one event per few hundred ms keeps a
     *                   tick comfortably inside the one-minute schedule.
     */
    public function run($limit = 200)
    {
        if (!is_cli()) { show_404(); return; }

        if ($this->config->item('automation_engine_enabled') !== true) {
            // Deliberately visible (log_threshold on live is 1): "why did nothing happen?"
            // must have an answer in the log, not silence.
            log_message('error', 'automation_worker: skipped, automation_engine_enabled is not TRUE');
            echo "automation engine disabled (config automation_engine_enabled)\n";
            return;
        }

        if (!$this->db->table_exists('events') || !$this->db->table_exists('automation_rules')) {
            log_message('error', 'automation_worker: event/automation tables missing — run cron_hub migrate');
            echo "automation tables missing\n";
            return;
        }

        $got = $this->db->query("SELECT GET_LOCK('" . self::LOCK . "', 0) AS l")->row();
        if (empty($got) || (int) $got->l !== 1) {
            echo "another automation worker tick is running\n";
            return;
        }

        $started = microtime(true);
        $limit = max(1, min(2000, (int) $limit));
        $seen = 0; $totals = array('rules' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0);
        $error = null;

        try {
            $this->load->library('automation_engine');

            $events = $this->db->from('events')->where('processed_at IS NULL', null, false)
                ->order_by('id', 'ASC')->limit($limit)->get()->result_array();

            foreach ($events as $event) {
                $seen++;
                try {
                    $r = $this->automation_engine->process_event($event);
                    foreach ($r as $k => $v) $totals[$k] = (isset($totals[$k]) ? $totals[$k] : 0) + $v;
                } catch (Throwable $e) {
                    // one poisoned event must not stall the queue behind it
                    log_message('error', 'automation_worker event ' . $event['id'] . ': ' . $e->getMessage());
                    $error = 'event ' . $event['id'] . ': ' . $e->getMessage();
                }
                // stamped even when the rules failed: the run/action log holds the failure,
                // and re-running the event would re-deliver the actions that did succeed.
                $this->db->where('id', (int) $event['id'])->update('events', array('processed_at' => date('Y-m-d H:i:s')));
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            log_message('error', 'automation_worker: ' . $e->getMessage());
        }

        // Task 24 (brief 23): the inactivity scanner runs inside the same locked tick, after
        // the queue drain, so it never overlaps another tick and its emitted events are
        // simply picked up on the NEXT tick (same as any other event) rather than this one.
        $inactivity = array('scanned' => 0, 'emitted' => 0);
        try {
            $inactivity = $this->scan_inactivity();
        } catch (Throwable $e) {
            log_message('error', 'automation_worker scan_inactivity: ' . $e->getMessage());
        }

        $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");

        $detail = "events {$seen}, rules matched {$totals['rules']}, ok {$totals['success']}, "
                . "failed {$totals['failed']}, skipped {$totals['skipped']}, duplicate {$totals['duplicate']}, "
                . "inactivity scanned {$inactivity['scanned']} emitted {$inactivity['emitted']}";
        ops_health_record('automation_worker', $error === null, microtime(true) - $started, $error === null ? $detail : $error);
        echo $detail . "\n";
    }

    /**
     * Task 24 review fix (brief 23): the "inactivity" trigger has no natural emitter —
     * nothing else in the app knows a conversation went quiet, so this tick scans for it and
     * emits a real `inactivity` event, which then flows through the SAME event-driven
     * pipeline as every other trigger (no second engine). Threshold: config item
     * 'automation_inactivity_hours' (default 24).
     *
     * Guarantees (the first version of this method got #2 wrong twice — see history):
     *  1. Only tenants with an ACTIVE rule whose trigger_event is 'inactivity' are scanned
     *     at all — a tenant with no such rule never has its conversations touched or an
     *     event written for it. (Automation_engine::inactivity_tenants(), pure.)
     *  2. Exactly one `inactivity` event per idle SPELL per conversation, not one per tick,
     *     AND an already-flagged conversation can never starve the rest of the tenant's
     *     queue. Automation_engine::inactivity_candidate_sql() puts the "already has a newer
     *     inactivity event than last_inbound_at" exclusion INSIDE the WHERE clause, before
     *     LIMIT is applied — a flagged row is excluded from the result set entirely, so it
     *     never occupies a LIMIT slot that a genuinely new-idle conversation needed.
     *     (Round 1's bug: that exclusion ran in PHP AFTER the LIMIT had already been filled
     *     by rows that were flagged, which is starvation, not just a missed test case.)
     * Oldest-`last_inbound_at`-first (ORDER BY, also inside that same query) means that once
     * the currently-oldest idle conversations are flagged, the NEXT tick's query naturally
     * surfaces the next-oldest ones — no separate pagination bookkeeping needed.
     * Automation_engine::inactivity_emit_targets() is kept as a second, pure, defense-in-depth
     * check on the rows the query returns (belt-and-braces, brief 51: no silent failure from
     * a single layer's mistake) — with the SQL fix, it should now be a no-op in practice.
     */
    public function scan_inactivity($limit = 200)
    {
        if (!$this->db->table_exists('conversations') || !$this->db->table_exists('automation_rules')
            || !$this->db->table_exists('events')) {
            return array('scanned' => 0, 'emitted' => 0, 'tenants' => 0);
        }
        try {
            $this->load->library('automation_engine');
            $hours = (float) $this->config->item('automation_inactivity_hours');
            if ($hours <= 0) $hours = 24.0;
            $cutoff = date('Y-m-d H:i:s', time() - (int) ($hours * 3600));

            $rule_rows = $this->db->select('user_id, status, trigger_event')->from('automation_rules')
                ->where('status', 'active')->where('trigger_event', 'inactivity')->get()->result_array();
            $tenants = Automation_engine::inactivity_tenants($rule_rows);

            $scanned = 0; $emitted = 0;
            if (empty($tenants)) return array('scanned' => 0, 'emitted' => 0, 'tenants' => 0);

            $per_tenant = max(1, (int) floor(max(1, (int) $limit) / count($tenants)));
            $sql = Automation_engine::inactivity_candidate_sql();

            foreach ($tenants as $user_id) {
                $candidates = $this->db->query($sql, array($user_id, $cutoff, $per_tenant))->result_array();
                if (empty($candidates)) continue;
                $scanned += count($candidates);

                // Defense-in-depth (see docblock): the SQL above already excludes flagged
                // rows via its own NOT EXISTS, so re-checking against a fresh read of the
                // same events is expected to change nothing in practice — it is a second,
                // independently-implemented layer, not a second copy of the same bug.
                $ids = array();
                foreach ($candidates as $c) $ids[] = (int) $c['id'];
                $existing = $this->db->select('subject_id, created_at')->from('events')
                    ->where('user_id', $user_id)->where('event_type', 'inactivity')->where('subject_type', 'conversation')
                    ->where_in('subject_id', $ids)->get()->result_array();

                $targets = Automation_engine::inactivity_emit_targets($candidates, $existing, $hours);
                if (empty($targets)) continue;
                $targets = array_flip($targets); // O(1) lookup below

                foreach ($candidates as $c) {
                    if (!isset($targets[(string) $c['id']])) continue;
                    $n = event_emit((int) $user_id, 'inactivity', 'conversation', $c['id'], array(
                        'idle_hours'      => $hours,
                        'last_inbound_at' => $c['last_inbound_at'],
                        'platform'        => $c['platform'],
                    ));
                    if ($n > 0) $emitted++;
                }
            }
            return array('scanned' => $scanned, 'emitted' => $emitted, 'tenants' => count($tenants));
        } catch (Throwable $e) {
            log_message('error', 'automation_worker scan_inactivity: ' . $e->getMessage());
            return array('scanned' => 0, 'emitted' => 0, 'tenants' => 0);
        }
    }

    /**
     * Diagnostics: what is waiting, oldest first.
     *   php index.php automation_worker pending [limit]
     */
    public function pending($limit = 20)
    {
        if (!is_cli()) { show_404(); return; }
        $rows = $this->db->select('id, user_id, event_type, created_at')->from('events')
            ->where('processed_at IS NULL', null, false)->order_by('id', 'ASC')->limit(max(1, (int) $limit))->get()->result_array();
        echo count($rows) . " pending event(s) shown\n";
        foreach ($rows as $r) echo "  #{$r['id']} user {$r['user_id']} {$r['event_type']} {$r['created_at']}\n";
    }
}
