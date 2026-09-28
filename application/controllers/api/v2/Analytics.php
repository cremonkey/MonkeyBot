<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'libraries/Api_v2_controller.php';

/**
 * GET /api/v2/analytics — Task 28 (C13, brief §37). Read-only KPIs from the pre-computed
 * `analytics_daily` table (Task 21 / C6) — never a live scan, same source Analytics_hub.php
 * reads, via application/helpers/analytics_kpi_helper.php's pure analytics_kpi_from_totals().
 * The day-range SUM query itself mirrors Analytics_hub::analytics_daily_totals() (private
 * there); kept here as a small private method rather than pulled into the pure helper, which is
 * deliberately DB-free (see analytics_kpi_helper.php's header) and reused by Cron_hub.php too.
 */
class Analytics extends Api_v2_controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('analytics_kpi');
        $this->require_scope('analytics');
    }

    /** GET /api/v2/analytics/kpis?days=7 */
    public function kpis()
    {
        $days = (int) $this->input->get('days');
        if ($days <= 0 || $days > 90) $days = 7;

        if (!$this->db->table_exists('analytics_daily')) {
            $this->ok(array('collecting' => true, 'kpis' => array()), array('days' => $days));
        }

        $totals = $this->daily_totals($days, 0);
        if (empty($totals)) {
            $this->ok(array('collecting' => true, 'kpis' => array()), array('days' => $days));
        }

        $this->ok(array('collecting' => false, 'kpis' => analytics_kpi_from_totals($totals)), array('days' => $days));
    }

    /**
     * Sum every analytics_daily metric (channel='all' rows only) for the $days ending
     * $offset_days ago, scoped to $this->uid — same shape as Analytics_hub's private method,
     * duplicated intentionally small rather than adding a DB-touching function to the pure
     * analytics_kpi_helper.php.
     */
    private function daily_totals($days, $offset_days)
    {
        $end = date('Y-m-d', strtotime('-' . (int) $offset_days . ' days'));
        $start = date('Y-m-d', strtotime($end . ' -' . ((int) $days - 1) . ' days'));
        $rows = $this->db->query(
            "SELECT metric, SUM(value) v FROM analytics_daily
             WHERE user_id=? AND channel='all' AND day BETWEEN ? AND ? GROUP BY metric",
            array($this->uid, $start, $end))->result_array();
        $out = array();
        foreach ($rows as $r) $out[$r['metric']] = (float) $r['v'];
        return $out;
    }
}
