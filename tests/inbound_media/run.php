<?php
/**
 * Inbound media (WhatsApp Cloud, Telegram, OpenWA) unit tests. No DB, no CodeIgniter bootstrap,
 * no network: exercises application/helpers/inbound_media_helper.php with a fake HTTP transport
 * (records every request, answers like the provider), and the inbox renderer that reads
 * livechat_messages.media_json (inbox_helper.php). HTTP+DB coverage of the three webhooks is
 * docker/scripts/staging_inbound_media_tests.sh.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/inbound_media/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('the pure inbound_media functions must not need a CI instance'); }
$LOGS = array();
function log_message($level, $msg) { global $LOGS; $LOGS[] = array($level, $msg); }

require __DIR__ . '/../../application/helpers/reengage_helper.php';
require __DIR__ . '/../../application/helpers/channel_policy_helper.php';
require __DIR__ . '/../../application/helpers/inbox_helper.php';
require __DIR__ . '/../../application/helpers/inbound_media_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ------------------------------------------------------------------ sample files (real bytes)
function sample_jpeg() { $im = imagecreatetruecolor(8, 8); ob_start(); imagejpeg($im); return ob_get_clean(); }
function sample_png() { $im = imagecreatetruecolor(8, 8); ob_start(); imagepng($im); return ob_get_clean(); }
function sample_ogg() {
    return "OggS\x00\x02" . str_repeat("\x00", 8) . "\x01\x00\x00\x00" . "\x00\x00\x00\x00" . "\x00\x00\x00\x00" . "\x01\x13"
        . "OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00" . str_repeat("\x00", 200);
}
function sample_mp4() { return "\0\0\0\x18ftypisom\0\0\x02\0isommp41" . str_repeat("\0", 200); }
function sample_m4a_as_mp4() { return sample_mp4(); } // WhatsApp audio/mp4 that finfo calls video/mp4
function sample_pdf() { return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n"; }
function sample_docx() {
    $tmp = tempnam(sys_get_temp_dir(), 'dx');
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document/>');
    $z->close(); $b = file_get_contents($tmp); unlink($tmp); return $b;
}

$root = sys_get_temp_dir() . '/inbound_media_test_' . bin2hex(random_bytes(4));
mkdir($root);
$NOW = mktime(12, 0, 0, 3, 15, 2026);

// ============================================================================================
echo "== type detection from bytes (allow-list, never the sender's name) ==\n";
$cases = array(
    array('jpeg', sample_jpeg(), '', 'image/jpeg', 'jpg', 'image'),
    array('png', sample_png(), 'image/jpeg', 'image/png', 'png', 'image'), // declared type ignored
    array('ogg opus voice note', sample_ogg(), 'audio/ogg; codecs=opus', 'audio/ogg', 'ogg', 'audio'),
    array('mp4 video', sample_mp4(), 'video/mp4', 'video/mp4', 'mp4', 'video'),
    array('mp4 container declared audio -> m4a audio', sample_m4a_as_mp4(), 'audio/mp4', 'audio/mp4', 'm4a', 'audio'),
    array('pdf', sample_pdf(), 'application/pdf', 'application/pdf', 'pdf', 'file'),
    array('docx (zip + declared OOXML + [Content_Types].xml)', sample_docx(),
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx', 'file'),
);
foreach ($cases as $c) {
    $d = inbound_media_detect($c[1], $c[2]);
    check('detect ' . $c[0], array($d['ok'], $d['mime'], $d['ext'], $d['type']), array(true, $c[3], $c[4], $c[5]));
}
$refused = array(
    'ELF executable' => array("\x7fELF\x02\x01\x01" . str_repeat("\0", 60), 'application/pdf'),
    'SVG (scriptable)' => array('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
    'HTML' => array('<html><body>hi</body></html>', 'text/html'),
    'PHP script declared as image' => array("<?php system(\$_GET['c']);", 'image/jpeg'),
    'plain zip declared docx without [Content_Types].xml' => array("PK\x03\x04" . str_repeat("\0", 60), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    'zip declared zip' => array(sample_docx(), 'application/zip'),
    'empty' => array('', 'image/jpeg'),
);
foreach ($refused as $name => $c) check('refused: ' . $name, inbound_media_detect($c[0], $c[1])['ok'], false);
check('refused: JPEG polyglot carrying PHP', inbound_media_detect(sample_jpeg() . '<?php echo 1; ?>', 'image/jpeg')['ok'], false);
check('refused: JPEG carrying <script>', inbound_media_detect(sample_jpeg() . '<script>x</script>', 'image/jpeg')['ok'], false);
$exts = array(); foreach (inbound_media_allowed_types() as $v) $exts[] = $v[0];
check('no executable/script extension in the allow-list', array_values(array_intersect($exts, array('php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js', 'exe', 'sh', 'pl', 'cgi'))), array());

// ============================================================================================
echo "\n== storage name and write ==\n";
check('storage name shape', inbound_media_storage_name(42, 'jpg', 'abcdef0123456789abcdef01', $NOW), 'upload/inbox_in/2026/03/42_abcdef0123456789abcdef01.jpg');
check('storage name: user id is cast to int (no traversal)', inbound_media_storage_name('../../x', 'jpg', 'abcdef0123456789abcdef01', $NOW), 'upload/inbox_in/2026/03/0_abcdef0123456789abcdef01.jpg');
check('storage name: random part is hex only', inbound_media_storage_name(1, 'png', '../../etc/passwd0123456789abcdef', $NOW), 'upload/inbox_in/2026/03/1_ecad0123456789abcdef.png');
check('storage name: unknown extension refused', inbound_media_storage_name(1, 'php', 'abcdef0123456789abcdef01', $NOW), null);
check('storage name: short random refused', inbound_media_storage_name(1, 'jpg', 'abc', $NOW), null);

$s = inbound_media_store_bytes(7, sample_jpeg(), 'image/jpeg', $root, 1024 * 1024, $NOW, 'aaaaaaaaaaaaaaaaaaaaaaaa');
check('store: ok', $s['ok'], true);
check('store: path', $s['media']['path'], 'upload/inbox_in/2026/03/7_aaaaaaaaaaaaaaaaaaaaaaaa.jpg');
check('store: bytes on disk', file_get_contents($root . '/' . $s['media']['path']), sample_jpeg());
check('store: file mode 0644', substr(sprintf('%o', fileperms($root . '/' . $s['media']['path'])), -4), '0644');
$s = inbound_media_store_bytes(7, str_repeat('A', 2048), 'image/jpeg', $root, 1024, $NOW, 'bbbbbbbbbbbbbbbbbbbbbbbb');
check('store: over the cap refused, nothing written', array($s['ok'], file_exists($root . '/upload/inbox_in/2026/03/7_bbbbbbbbbbbbbbbbbbbbbbbb.jpg')), array(false, false));
$s = inbound_media_store_bytes(7, "\x7fELF" . str_repeat("\0", 60), 'image/jpeg', $root, 1024 * 1024, $NOW, 'cccccccccccccccccccccccc');
check('store: executable refused, nothing written', array($s['ok'], count(glob($root . '/upload/inbox_in/2026/03/7_cccc*'))), array(false, 0));

check('limits: defaults 16 MB / 8 s', inbound_media_limits(null, null), array(16777216, 8));
check('limits: config values used', inbound_media_limits(5000000, 4), array(5000000, 4));
check('limits: clamped', inbound_media_limits(999999999999, 999), array(67108864, 30));

// ============================================================================================
echo "\n== payload extraction ==\n";
check('wa image', inbound_media_extract_wa_cloud(array('type' => 'image', 'image' => array('id' => 'M1', 'mime_type' => 'image/jpeg', 'caption' => ' hi '))),
    array('kind' => 'image', 'label' => 'image', 'id' => 'M1', 'caption' => 'hi', 'mime' => 'image/jpeg', 'size' => 0));
check('wa voice note (audio.voice=true)', inbound_media_extract_wa_cloud(array('type' => 'audio', 'audio' => array('id' => 'A1', 'voice' => true)))['label'], 'voice note');
check('wa document -> file', inbound_media_extract_wa_cloud(array('type' => 'document', 'document' => array('id' => 'D1', 'filename' => '../../evil.php')))['kind'], 'file');
check('wa document: filename not carried at all', array_key_exists('filename', inbound_media_extract_wa_cloud(array('type' => 'document', 'document' => array('id' => 'D1', 'filename' => 'x.php')))), false);
check('wa text -> null', inbound_media_extract_wa_cloud(array('type' => 'text', 'text' => array('body' => 'hi'))), null);
check('wa location -> null', inbound_media_extract_wa_cloud(array('type' => 'location', 'location' => array())), null);

$tgp = array('message_id' => 5, 'caption' => 'look', 'photo' => array(
    array('file_id' => 'small', 'file_size' => 1000), array('file_id' => 'mid', 'file_size' => 50000), array('file_id' => 'huge', 'file_size' => 90000000)));
check('tg photo: largest size under the cap', inbound_media_extract_telegram($tgp, 16777216)['id'], 'mid');
check('tg photo: caption', inbound_media_extract_telegram($tgp, 16777216)['caption'], 'look');
check('tg voice', inbound_media_extract_telegram(array('voice' => array('file_id' => 'V', 'mime_type' => 'audio/ogg')), 0)['label'], 'voice note');
check('tg document', inbound_media_extract_telegram(array('document' => array('file_id' => 'D', 'file_name' => 'a.exe')), 0)['kind'], 'file');
check('tg animated sticker (.tgs) -> null', inbound_media_extract_telegram(array('sticker' => array('file_id' => 'S', 'is_animated' => true)), 0), null);
check('tg text -> null', inbound_media_extract_telegram(array('text' => 'hi'), 0), null);
check('openwa imageMessage', inbound_media_extract_openwa(array('type' => 'imageMessage')), array('kind' => 'image', 'label' => 'image'));
check('openwa conversation -> null', inbound_media_extract_openwa(array('type' => 'conversation')), null);
check('placeholder', inbound_media_placeholder('voice note'), '[voice note]');

// ============================================================================================
echo "\n== WhatsApp Cloud fetch (fake transport) ==\n";
class FakeHttp {
    public $calls = array(); public $routes = array();
    public function __invoke($url, array $headers, $max, $timeout) {
        $this->calls[] = array('url' => $url, 'headers' => $headers, 'max' => $max, 'timeout' => $timeout);
        foreach ($this->routes as $prefix => $resp) {
            if (strpos($url, $prefix) === 0) {
                if (is_callable($resp)) $resp = $resp($url, $max);
                if (isset($resp['body']) && strlen($resp['body']) > $max) return array('ok' => false, 'status' => 200, 'body' => '', 'content_type' => '', 'error' => 'file larger than ' . $max . ' bytes');
                return $resp + array('ok' => true, 'status' => 200, 'body' => '', 'content_type' => '', 'error' => '');
            }
        }
        return array('ok' => false, 'status' => 0, 'body' => '', 'content_type' => '', 'error' => 'transport: Could not resolve host');
    }
}
$G = 'https://graph.facebook.com/v26.0/';
$h = new FakeHttp();
$h->routes[$G . 'MEDIA1'] = array('body' => json_encode(array('url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'image/jpeg', 'file_size' => 900)));
$h->routes['https://lookaside.fbsbx.com/'] = array('body' => sample_jpeg(), 'content_type' => 'image/jpeg');
$f = inbound_media_fetch_wa_cloud($G, 'MEDIA1', 'TOKEN', $h, 16777216, 8);
check('wa fetch: ok', $f['ok'], true);
check('wa fetch: bytes', $f['bytes'], sample_jpeg());
check('wa fetch: 2 requests, both with the bearer token', array(count($h->calls), $h->calls[0]['headers'], $h->calls[1]['headers']),
    array(2, array('Authorization: Bearer TOKEN'), array('Authorization: Bearer TOKEN')));
check('wa fetch: lookup URL is {graph}/{media-id}', $h->calls[0]['url'], $G . 'MEDIA1');
check('wa fetch: download capped at max bytes, short timeout', array($h->calls[1]['max'], $h->calls[1]['timeout']), array(16777216, 8));

$h = new FakeHttp();
$h->routes[$G] = array('body' => json_encode(array('url' => 'https://lookaside.fbsbx.com/x', 'file_size' => 99999999)));
$f = inbound_media_fetch_wa_cloud($G, 'BIG', 'TOKEN', $h, 16777216, 8);
check('wa fetch: declared size over cap -> refused before download', array($f['ok'], count($h->calls)), array(false, 1));
$h = new FakeHttp();
$h->routes[$G] = array('body' => json_encode(array('url' => 'http://evil.example/x')));
check('wa fetch: non-https media URL refused', inbound_media_fetch_wa_cloud($G, 'X', 'TOKEN', $h, 1000, 8)['ok'], false);
check('wa fetch: invalid media id refused without a request', array(inbound_media_fetch_wa_cloud($G, '../me', 'TOKEN', $h, 1000, 8)['ok'], count($h->calls)), array(false, 1));
$h = new FakeHttp();
$h->routes[$G] = array('ok' => false, 'status' => 401, 'body' => '{"error":{}}');
$f = inbound_media_fetch_wa_cloud($G, 'X', 'SECRET_TOKEN', $h, 1000, 8);
check('wa fetch: provider 401 -> failure', $f['ok'], false);
check('wa fetch: error never contains the token', strpos($f['error'], 'SECRET_TOKEN'), false);
$h = new FakeHttp();
$h->routes[$G] = array('body' => json_encode(array('url' => 'https://lookaside.fbsbx.com/x')));
$h->routes['https://lookaside.fbsbx.com/'] = array('body' => str_repeat('A', 5000));
check('wa fetch: body over cap aborts', inbound_media_fetch_wa_cloud($G, 'X', 'T', $h, 1000, 8)['ok'], false);

echo "\n== Telegram fetch (fake transport) ==\n";
$T = 'https://api.telegram.org';
$tok = '123456:ABC-def_SECRET';
$h = new FakeHttp();
$h->routes[$T . '/bot' . $tok . '/getFile'] = array('body' => json_encode(array('ok' => true, 'result' => array('file_id' => 'F', 'file_path' => 'voice/file_3.oga', 'file_size' => 400))));
$h->routes[$T . '/file/bot' . $tok . '/voice/file_3.oga'] = array('body' => sample_ogg(), 'content_type' => 'application/octet-stream');
$f = inbound_media_fetch_telegram($T, $tok, 'AgAD-x_1', $h, 16777216, 8);
check('tg fetch: ok', $f['ok'], true);
check('tg fetch: getFile URL', $h->calls[0]['url'], $T . '/bot' . $tok . '/getFile?file_id=AgAD-x_1');
check('tg fetch: download URL', $h->calls[1]['url'], $T . '/file/bot' . $tok . '/voice/file_3.oga');
$h = new FakeHttp();
$h->routes[$T] = array('body' => json_encode(array('ok' => true, 'result' => array('file_path' => '../../etc/passwd'))));
check('tg fetch: traversal in file_path refused, no download', array(inbound_media_fetch_telegram($T, $tok, 'F', $h, 1000, 8)['ok'], count($h->calls)), array(false, 1));
$h = new FakeHttp();
$h->routes[$T] = array('ok' => false, 'status' => 400, 'body' => '{"ok":false}');
$f = inbound_media_fetch_telegram($T, $tok, 'F', $h, 1000, 8);
check('tg fetch: error never contains the bot token', array($f['ok'], strpos($f['error'], 'SECRET')), array(false, false));
check('tg fetch: malformed token refused without a request', inbound_media_fetch_telegram($T, 'x/../y', 'F', new FakeHttp(), 1000, 8)['ok'], false);

// ============================================================================================
echo "\n== capture (fetch + store + log) ==\n";
$opts = array('root' => $root, 'max_bytes' => 16777216, 'now' => $NOW, 'random_hex' => 'dddddddddddddddddddddddd');
$LOGS = array();
$c = inbound_media_capture('wa', 9, array('kind' => 'image', 'id' => 'M', 'mime' => 'image/jpeg', 'size' => 10), function () {
    return array('ok' => true, 'error' => '', 'bytes' => sample_jpeg(), 'mime' => 'image/jpeg', 'source_url' => 'https://x/y');
}, $opts);
check('capture: stored', array($c['ok'], $c['media']['path'], $c['media']['type']), array(true, 'upload/inbox_in/2026/03/9_dddddddddddddddddddddddd.jpg', 'image'));
check('capture: nothing logged on success', $LOGS, array());
$c = inbound_media_capture('tg', 9, array('kind' => 'audio', 'id' => 'M', 'size' => 99999999), function () { throw new Exception('must not be called'); }, $opts);
check('capture: declared size over cap -> no fetch, error logged', array($c['ok'], $LOGS[0][0]), array(false, 'error'));
$LOGS = array();
$c = inbound_media_capture('wa', 9, array('kind' => 'image', 'id' => 'M'), function () { throw new RuntimeException('boom'); }, $opts);
check('capture: exception -> ok=false, logged at error', array($c['ok'], $LOGS[0][0], strpos($LOGS[0][1], 'boom') !== false), array(false, 'error', true));
$LOGS = array();
$c = inbound_media_capture('wa', 9, array('kind' => 'file', 'id' => 'M'), function () {
    return array('ok' => true, 'error' => '', 'bytes' => "\x7fELF" . str_repeat("\0", 60), 'mime' => 'application/pdf', 'source_url' => 'https://x');
}, $opts);
check('capture: disallowed bytes -> not stored, logged', array($c['ok'], $c['media'], count($LOGS)), array(false, null, 1));

// ============================================================================================
echo "\n== media_json and the inbox renderer ==\n";
$stored = array('type' => 'image', 'path' => 'upload/inbox_in/2026/03/9_dddddddddddddddddddddddd.jpg', 'mime' => 'image/jpeg', 'size' => 10);
$mj = inbound_media_json('image', $stored);
check('media_json stored shape', json_decode($mj, true), $stored);
check('media_json not-stored marker', json_decode(inbound_media_json('audio', null), true), array('type' => 'audio', 'path' => null));

$B = 'https://app.example.com/';
$r = inbox_message_render('[image]', $mj, $B);
check('render: placeholder text hidden, image attachment with absolute URL', array($r['text'], $r['attachments'], $r['media_unavailable']),
    array('', array(array('type' => 'image', 'url' => 'https://app.example.com/upload/inbox_in/2026/03/9_dddddddddddddddddddddddd.jpg')), false));
$r = inbox_message_render('look at this', $mj, $B);
check('render: caption kept as text next to the image', array($r['text'], count($r['attachments'])), array('look at this', 1));
foreach (array('audio' => 'ogg', 'video' => 'mp4', 'file' => 'pdf') as $t => $e) {
    $r = inbox_message_render('[x]', json_encode(array('type' => $t, 'path' => 'upload/inbox_in/2026/03/9_dddddddddddddddddddddddd.' . $e)), $B);
    check("render: $t attachment", $r['attachments'][0]['type'], $t);
}
$r = inbox_message_render('[non-text message]', inbound_media_json('video', null), $B);
check('render: not-stored marker -> media_unavailable, no text', array($r['text'], $r['attachments'], $r['media_unavailable']), array('', array(), true));
$r = inbox_message_render('caption only', inbound_media_json('image', null), $B);
check('render: caption + not-stored marker', array($r['text'], $r['media_unavailable']), array('caption only', true));
$r = inbox_message_render('[non-text message]', null, $B);
check('render: no column (before the migration) -> as before', array($r['text'], $r['media_unavailable']), array('', true));
$evil = array(
    'traversal' => 'upload/inbox_in/2026/03/../../../application/config/database.php',
    'php ext' => 'upload/inbox_in/2026/03/9_dddddddddddddddddddddddd.php',
    'other dir' => 'upload/inbox/2026/03/inbox9_dddddddddddddddddddddddd.jpg',
    'absolute url' => 'https://evil.example/x.jpg',
    'javascript' => 'javascript:alert(1)',
);
foreach ($evil as $name => $p) {
    $r = inbox_message_render('[image]', json_encode(array('type' => 'image', 'path' => $p)), $B);
    check('render: rejected path (' . $name . ') is never a URL', $r['attachments'], array());
}
check('render: unknown type ignored', inbox_message_render('hi', json_encode(array('type' => 'script', 'path' => null)), $B)['media_unavailable'], false);
check('render: garbage media_json ignored', inbox_message_render('hi', '{not json', $B)['text'], 'hi');
check('preview: stored placeholder', inbox_message_preview('[voice note]'), '[voice note]');

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($root);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
