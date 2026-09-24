<?php
/**
 * Worker-status unit tests (task 6 / brief §51 — no silent queueing when workers are off).
 * No CodeIgniter, no database, no network, no filesystem: automation_workers_status() takes
 * the flag value and an injectable $health array so every state is exercised deterministically.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/worker_status/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
date_default_timezone_set('UTC');
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

require __DIR__ . '/../../application/helpers/ops_health_helper.php';

$PASS = 0; $FAIL = 0;
function ok($name) { global $PASS; $PASS++; echo "PASS  $name\n"; }
function bad($name, $why) { global $FAIL; $FAIL++; echo "FAIL  $name  -> $why\n"; }
function is_same($name, $expected, $actual) {
    if ($expected === $actual) ok($name);
    else bad($name, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

$NOW = 1_700_000_000;

function iso($NOW, $seconds_ago) { return date('c', $NOW - $seconds_ago); }

// ---------------------------------------------------------------- flag off = paused_by_flag

$s = automation_workers_status(false, array(), $NOW);
is_same('flag off: state', 'paused_by_flag', $s['state']);
is_same('flag off: workers_paused', true, $s['workers_paused']);
is_same('flag off: message_en not empty', true, $s['message_en'] !== '');
is_same('flag off: message_ar not empty', true, $s['message_ar'] !== '');

$s = automation_workers_status(null, array(), $NOW);   // unset config item = FALSE = off, never TRUE
is_same('flag unset (null): state', 'paused_by_flag', $s['state']);
is_same('flag unset (null): workers_paused', true, $s['workers_paused']);

// ---------------------------------------------------------------- flag on, both jobs fresh = running

$fresh_health = array(
    'automation_worker' => array('last_success_at' => iso($NOW, 30)),
    'sequence_worker' => array('last_success_at' => iso($NOW, 300)),
);
$s = automation_workers_status(true, $fresh_health, $NOW);
is_same('both fresh: state', 'running', $s['state']);
is_same('both fresh: workers_paused', false, $s['workers_paused']);
is_same('both fresh: no stale jobs', array(), $s['stale_jobs']);

// exactly at the threshold (600s) still counts as fresh (not > threshold)
$edge_health = array(
    'automation_worker' => array('last_success_at' => iso($NOW, 600)),
    'sequence_worker' => array('last_success_at' => iso($NOW, 600)),
);
$s = automation_workers_status(true, $edge_health, $NOW);
is_same('exactly 600s old: state', 'running', $s['state']);

// ---------------------------------------------------------------- flag on, one job stale = stale

$one_stale = array(
    'automation_worker' => array('last_success_at' => iso($NOW, 700)),   // > 600s
    'sequence_worker' => array('last_success_at' => iso($NOW, 30)),
);
$s = automation_workers_status(true, $one_stale, $NOW);
is_same('one stale: state', 'stale', $s['state']);
is_same('one stale: workers_paused', true, $s['workers_paused']);
is_same('one stale: stale_jobs lists only the stale one', array('automation_worker'), $s['stale_jobs']);
is_same('one stale: message_en not empty', true, $s['message_en'] !== '');
is_same('one stale: message_ar not empty', true, $s['message_ar'] !== '');

// ---------------------------------------------------------------- flag on, never ran = stale

$never_ran = array();
$s = automation_workers_status(true, $never_ran, $NOW);
is_same('never ran: state', 'stale', $s['state']);
is_same('never ran: workers_paused', true, $s['workers_paused']);
is_same('never ran: both jobs listed stale', array('automation_worker', 'sequence_worker'), $s['stale_jobs']);

// production today: host cron has no automation_worker/sequence_worker lines at all — this is
// exactly that shape (flag TRUE but health file has no entry for either job) and MUST be paused.
$s = automation_workers_status(true, array(), $NOW);
is_same('production-shaped (flag on, no health rows): workers_paused', true, $s['workers_paused']);

// ---------------------------------------------------------------- flag on, both jobs stale = stale

$both_stale = array(
    'automation_worker' => array('last_success_at' => iso($NOW, 601)),
    'sequence_worker' => array('last_success_at' => iso($NOW, 9999)),
);
$s = automation_workers_status(true, $both_stale, $NOW);
is_same('both stale: state', 'stale', $s['state']);
is_same('both stale: stale_jobs lists both', array('automation_worker', 'sequence_worker'), $s['stale_jobs']);

// ---------------------------------------------------------------- malformed last_success_at

$garbage_health = array(
    'automation_worker' => array('last_success_at' => 'not-a-date'),
    'sequence_worker' => array('last_success_at' => iso($NOW, 30)),
);
$s = automation_workers_status(true, $garbage_health, $NOW);
is_same('unparsable timestamp treated as stale', 'stale', $s['state']);
is_same('unparsable timestamp: stale_jobs', array('automation_worker'), $s['stale_jobs']);

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
