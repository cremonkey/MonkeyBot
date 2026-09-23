<?php
/**
 * Fake OpenWA-compatible gateway for docker/scripts/staging_inbox_tests.sh ONLY. Runs as its own
 * service in the isolated inbox test stack (docker-compose.inbox2-test.yml) under PHP's built-in
 * server, never in the app or behind nginx (nginx also denies /tests/):
 *
 *   php -S 0.0.0.0:8080 tests/inbox/fake_gateway.php
 *
 * Appends every request (method, path, API key header, JSON body) as one JSON line to
 * /tmp/fakegw.log, so the suite can assert the exact request the real adapter made. Answers like
 * the gateway does: 201 {"id": ...}. A session id containing "fail" gets a 500, to exercise the
 * send-failure path. It never fetches the media URL it is given.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
file_put_contents('/tmp/fakegw.log', json_encode(array(
    'method' => $_SERVER['REQUEST_METHOD'],
    'path'   => $path,
    'api_key' => isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : null,
    'body'   => json_decode($body, true),
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if (strpos($path, 'fail') !== false) {
    http_response_code(500);
    echo json_encode(array('statusCode' => 500, 'error' => 'fake gateway failure'));
    return true;
}
http_response_code(201);
echo json_encode(array('id' => 'fake-' . substr(sha1($body . microtime()), 0, 12)));
return true;
