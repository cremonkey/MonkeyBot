<?php
/**
 * Fake Pusher Channels HTTP API for the isolated realtime test stack (docker-compose.rt-test.yml).
 * Records every trigger/batch request so the suite can prove what the app published (channel,
 * payload, no message content) without any real Pusher account. Never used in production.
 *   POST /apps/<id>/batch_events | /apps/<id>/events  -> 200 {} and appended to the log
 *   GET  /__log  -> the recorded requests, one JSON object per line
 *   GET  /__reset -> clears the log
 *   any path containing "slow" in the app id sleeps 5s first (timeout check)
 */
$log = '/tmp/fake_pusher.log';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__log') { header('Content-Type: text/plain'); echo @file_get_contents($log); return; }
if ($path === '/__reset') { @file_put_contents($log, ''); echo 'ok'; return; }
if (strpos($path, '/apps/slow') === 0) sleep(5);
file_put_contents($log, json_encode(array('method' => $_SERVER['REQUEST_METHOD'], 'path' => $path,
    'body' => json_decode(file_get_contents('php://input'), true))) . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo '{}';
