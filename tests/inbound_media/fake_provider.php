<?php
/**
 * Fake WhatsApp Cloud Graph + Telegram Bot API for docker/scripts/staging_inbound_media_tests.sh
 * ONLY. Runs under PHP's built-in server in the isolated stack (docker-compose.media-test.yml),
 * behind a TLS proxy that answers as graph.facebook.com and api.telegram.org INSIDE that
 * project's network only, so the webhooks run their production URLs unchanged.
 *
 * Appends every request (method, path, query, Authorization header) to /tmp/fakeprov.log.
 * Media ids / file ids pick the answer:
 *   IMG* jpeg, VOICE* ogg/opus, DOC* pdf, VID* mp4, EXE* an ELF binary (must be refused),
 *   BIG* declares 99 MB, FAIL* answers 500, SLOW* sleeps 20 s (must hit the client timeout).
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
file_put_contents('/tmp/fakeprov.log', json_encode(array(
    'method' => $_SERVER['REQUEST_METHOD'], 'host' => $_SERVER['HTTP_HOST'] ?? '', 'path' => $path, 'query' => $q,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
), JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

function bytes_for($id) {
    if (strpos($id, 'IMG') === 0) { $im = imagecreatetruecolor(16, 16); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30)); ob_start(); imagejpeg($im); return array(ob_get_clean(), 'image/jpeg'); }
    if (strpos($id, 'VOICE') === 0) return array("OggS\x00\x02" . str_repeat("\x00", 8) . "\x01\x00\x00\x00" . str_repeat("\x00", 8) . "\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00" . str_repeat("\x00", 300), 'audio/ogg');
    if (strpos($id, 'DOC') === 0) return array("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n", 'application/pdf');
    if (strpos($id, 'VID') === 0) return array("\0\0\0\x18ftypisom\0\0\x02\0isommp41" . str_repeat("\0", 300), 'video/mp4');
    if (strpos($id, 'EXE') === 0) return array("\x7fELF\x02\x01\x01" . str_repeat("\0", 300), 'application/pdf');
    return array('', 'application/octet-stream');
}
function out($code, $body, $type = 'application/json') { http_response_code($code); header('Content-Type: ' . $type); echo $body; return true; }

$id = '';
// --- WhatsApp Cloud: GET /vNN.N/{media-id} and GET /fakecdn/{media-id} (both need the bearer)
if (preg_match('#^/v[0-9.]+/([A-Za-z0-9_.\-]+)$#', $path, $m) || preg_match('#^/fakecdn/([A-Za-z0-9_\-]+)$#', $path, $m)) {
    $id = $m[1];
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer wa-token-a') return out(401, '{"error":{"message":"bad token"}}');
    if (strpos($id, 'FAIL') === 0) return out(500, '{"error":{"message":"fake failure"}}');
    if (strpos($id, 'SLOW') === 0) { sleep(20); return out(200, '{}'); }
    list($b, $mime) = bytes_for($id);
    if (strpos($path, '/fakecdn/') === 0) return out(200, $b, $mime);
    $size = strpos($id, 'BIG') === 0 ? 99000000 : strlen($b);
    return out(200, json_encode(array('url' => 'https://graph.facebook.com/fakecdn/' . $id, 'mime_type' => $mime, 'file_size' => $size, 'id' => $id, 'messaging_product' => 'whatsapp'), JSON_UNESCAPED_SLASHES));
}
// --- Telegram: /bot{token}/getFile?file_id= and /file/bot{token}/{file_path}
if (preg_match('#^/bot([^/]+)/getFile$#', $path, $m)) {
    if ($m[1] !== '777:tg-token_a') return out(401, '{"ok":false,"error_code":401}');
    $id = (string) ($q['file_id'] ?? '');
    if (strpos($id, 'FAIL') === 0) return out(400, '{"ok":false,"error_code":400,"description":"Bad Request: file not found"}');
    if (strpos($id, 'TRAV') === 0) return out(200, json_encode(array('ok' => true, 'result' => array('file_id' => $id, 'file_path' => '../../etc/passwd'))));
    list($b) = bytes_for($id);
    $size = strpos($id, 'BIG') === 0 ? 99000000 : strlen($b);
    return out(200, json_encode(array('ok' => true, 'result' => array('file_id' => $id, 'file_size' => $size, 'file_path' => 'media/' . $id . '.bin')), JSON_UNESCAPED_SLASHES));
}
if (preg_match('#^/file/bot([^/]+)/media/([A-Za-z0-9_\-]+)\.bin$#', $path, $m)) {
    if ($m[1] !== '777:tg-token_a') return out(404, 'not found', 'text/plain');
    list($b) = bytes_for($m[2]);
    return out(200, $b, 'application/octet-stream');
}
return out(404, '{"error":"no route"}');
