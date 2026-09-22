<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GET /health — liveness/readiness for uptime monitors and deploy checks (Phase 0, §8).
 *
 * Public and deliberately minimal: no tenant data, no config values.
 *   200 {"status":"ok"}        app + DB up, scheduler ran recently
 *   200 {"status":"degraded"}  app + DB up, scheduler stale (> 15 min) or never ran
 *   503 {"status":"down"}      DB unreachable
 */
class Health extends CI_Controller
{
    const SCHEDULER_STALE_AFTER_S = 900;

    public function index()
    {
        $this->output->set_content_type('application/json');
        $this->output->set_header('Cache-Control: no-store');

        $db_ok = false;
        try {
            $this->load->database();
            $q = $this->db->query('SELECT 1 AS ok');
            $db_ok = $q && (int) $q->row()->ok === 1;
        } catch (Throwable $e) {
            $db_ok = false;
        }

        $this->load->helper('ops_health');
        $age = ops_job_age_seconds('cron_hub');
        $scheduler_ok = $age !== null && $age <= self::SCHEDULER_STALE_AFTER_S;

        $status = !$db_ok ? 'down' : ($scheduler_ok ? 'ok' : 'degraded');
        $this->output->set_status_header($db_ok ? 200 : 503);
        $this->output->set_output(json_encode(array(
            'status'    => $status,
            'db'        => $db_ok,
            'scheduler' => array('last_success_age_s' => $age, 'stale' => !$scheduler_ok),
            'version'   => $this->_version(),
            'time'      => date('c'),
        )));
    }

    /** Short git commit of the running tree, read from .git without shelling out. */
    private function _version()
    {
        $git = FCPATH . '.git/';
        $head = @file_get_contents($git . 'HEAD');
        if (!$head) return null;
        $head = trim($head);
        if (strpos($head, 'ref: ') === 0) {
            $ref = substr($head, 5);
            $sha = @file_get_contents($git . $ref);
            if (!$sha) {
                $packed = (string) @file_get_contents($git . 'packed-refs');
                if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m)) $sha = $m[1];
            }
            $head = trim((string) $sha);
        }
        return preg_match('/^[0-9a-f]{40}$/', $head) ? substr($head, 0, 8) : null;
    }
}
