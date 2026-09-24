<?php
/**
 * Task 2 (A2) — dependency-free tests for the health-record counter payload and for the
 * two new callers (Tiktok_cron::poll_comments, Cron_job::lead_score_decay) actually wiring
 * ops_health_record() on both the success and the failure path.
 * No PHPUnit, no CodeIgniter bootstrap, no DB, no network.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ops_health/run.php
 *
 * ops_health_record() writes to APPPATH . 'cache/ops_health.json'. APPPATH is redefined to a
 * throwaway temp directory below so this test never touches the real application cache.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

define('BASEPATH', dirname(__DIR__, 2) . '/system/');
$TMP = sys_get_temp_dir() . '/ops_health_test_' . getmypid() . '_' . mt_rand(1000, 9999);
mkdir($TMP . '/cache', 0777, true);
define('APPPATH', $TMP . '/');
date_default_timezone_set('UTC');

$REPO = dirname(__DIR__, 2);
require $REPO . '/application/helpers/ops_health_helper.php';

$PASS = 0; $FAIL = 0;
function ok($n) { global $PASS; $PASS++; echo "  ok    $n\n"; }
function bad($n, $why) { global $FAIL; $FAIL++; echo "  FAIL  $n\n        $why\n"; }
function check($n, $cond, $why = '') { $cond ? ok($n) : bad($n, $why === '' ? 'condition was false' : $why); }
function same($n, $want, $got) { $want === $got ? ok($n) : bad($n, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true)); }

// ---------------------------------------------------------------------------
echo "== ops_health_record: counter payload shape ==\n";

// Success run with an explicit "processed N, failed N" counter string, matching the shape
// used by cron_hub/automation_worker/sequence_worker's existing $detail strings.
ops_health_record('lead_score_decay', true, 0.12, 'processed 7, failed 0');
$d = ops_health_read();
check('job key present', isset($d['lead_score_decay']));
check('last_success_at set', !empty($d['lead_score_decay']['last_success_at']));
same('runs == 1', 1, $d['lead_score_decay']['runs']);
same('failures == 0', 0, $d['lead_score_decay']['failures'] ?? 0);
same('last_result carries counters', 'processed 7, failed 0', $d['lead_score_decay']['last_result']);
check('duration recorded', $d['lead_score_decay']['last_duration_s'] === 0.12);

// Failure run: counters go into last_error, consecutive_failures increments.
ops_health_record('lead_score_decay', false, 0.05, 'Exception: boom (processed 0, failed 1)');
$d = ops_health_read();
same('runs == 2', 2, $d['lead_score_decay']['runs']);
same('failures == 1', 1, $d['lead_score_decay']['failures']);
same('consecutive_failures == 1', 1, $d['lead_score_decay']['consecutive_failures']);
check('last_error carries counters', strpos($d['lead_score_decay']['last_error'], 'processed 0, failed 1') !== false,
    $d['lead_score_decay']['last_error']);
check('last_success_at untouched by the failing run (still the earlier success)', !empty($d['lead_score_decay']['last_success_at']));

// A following success resets consecutive_failures, proving the counter series is per-run, not cumulative.
ops_health_record('lead_score_decay', true, 0.08, 'processed 3, failed 0');
$d = ops_health_read();
same('consecutive_failures resets to 0 after a success', 0, $d['lead_score_decay']['consecutive_failures']);
same('failures stays 1 (cumulative count of failed runs)', 1, $d['lead_score_decay']['failures']);

// ops_job_age_seconds reads back last_success_at, not last_run_at.
ops_health_record('tiktok_poll_comments', true, 1.0, 'campaigns 2, processed 5, failed 1');
$age = ops_job_age_seconds('tiktok_poll_comments');
check('age is a small non-negative number just after a success', $age !== null && $age >= 0 && $age < 5, var_export($age, true));
check('age is null for a job that never ran', ops_job_age_seconds('never_seen_job') === null);

// ---------------------------------------------------------------------------
echo "\n== source wiring: Tiktok_cron::poll_comments calls ops_health_record on both paths ==\n";

$tiktok_src = file_get_contents($REPO . '/application/modules/tiktok_bot/controllers/Tiktok_cron.php');
check('poll_comments loads the ops_health helper', strpos($tiktok_src, "'ops_health'") !== false || strpos($tiktok_src, '"ops_health"') !== false);
check('poll_comments wraps its body in try/catch', preg_match('/function poll_comments\(\).*?\btry\s*\{/s', $tiktok_src) === 1);
$poll_body_start = strpos($tiktok_src, 'function poll_comments()');
$poll_body = substr($tiktok_src, $poll_body_start, 2200);
check('poll_comments records success (ok = true)', preg_match("/ops_health_record\('tiktok_poll_comments',\s*true/", $poll_body) === 1, $poll_body);
check('poll_comments records failure (ok = false) in a catch block', preg_match("/catch\s*\(Throwable.*?ops_health_record\('tiktok_poll_comments',\s*false/s", $poll_body) === 1, $poll_body);
check('poll_comments payload mentions processed and failed counters', strpos($poll_body, 'processed') !== false && strpos($poll_body, 'failed') !== false);
check('a reply failure increments the failed counter', strpos($tiktok_src, "_health_stats['failed']++") !== false);
check('a reply success increments the processed counter', strpos($tiktok_src, "_health_stats['processed']++") !== false);

echo "\n== source wiring: Cron_job::lead_score_decay calls ops_health_record on both paths ==\n";

$cronjob_src = file_get_contents($REPO . '/application/controllers/Cron_job.php');
$decay_start = strpos($cronjob_src, 'function lead_score_decay');
check('lead_score_decay exists', $decay_start !== false);
$decay_body = substr($cronjob_src, $decay_start, 1400);
check('lead_score_decay wraps its body in try/catch', strpos($decay_body, 'try {') !== false);
check('lead_score_decay records success (ok = true)', preg_match("/ops_health_record\('lead_score_decay',\s*true/", $decay_body) === 1, $decay_body);
check('lead_score_decay records failure (ok = false) in a catch block', preg_match("/catch\s*\(Throwable.*?ops_health_record\('lead_score_decay',\s*false/s", $decay_body) === 1, $decay_body);
check('lead_score_decay payload mentions processed and failed counters', strpos($decay_body, 'processed') !== false && strpos($decay_body, 'failed') !== false);

// ---------------------------------------------------------------------------
echo "\n== source wiring: System_health shows all five jobs ==\n";

$sh_ctrl = file_get_contents($REPO . '/application/controllers/System_health.php');
foreach (array('cron_hub', 'tiktok_poll_comments', 'lead_score_decay', 'automation_worker', 'sequence_worker') as $job) {
    check("controller lists job '$job'", strpos($sh_ctrl, "'$job'") !== false);
}

$sh_view = file_get_contents($REPO . '/application/views/admin/system_health/index.php');
check('view iterates job_labels to render every job', strpos($sh_view, '$job_labels') !== false);
check('view shows last success', strpos($sh_view, 'last_success_at') !== false);
check('view shows last failure', strpos($sh_view, 'last_failure_at') !== false);
check('view shows run/failure counts', strpos($sh_view, "'runs'") !== false && strpos($sh_view, "'failures'") !== false);

// ---------------------------------------------------------------------------
echo "\n== cleanup ==\n";
@unlink($TMP . '/cache/ops_health.json');
@rmdir($TMP . '/cache');
@rmdir($TMP);
check('temp cache dir removed', !is_dir($TMP));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
