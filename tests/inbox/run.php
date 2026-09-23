<?php
/**
 * Unified Inbox unit tests (Phase 9.5). No DB, no CodeIgniter bootstrap, no network —
 * exercises the exact functions application/controllers/Inbox.php calls
 * (application/helpers/inbox_helper.php), plus their wiring into the real handoff state
 * machine (conversation_helper.php). HTTP+DB coverage (tenant isolation, IDOR, policy
 * denial on the wire, CSRF) is docker/scripts/staging_inbox_tests.sh.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/inbox/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('inbox_helper.php must not need a CI instance for these functions'); }
function log_message($level, $msg) { /* no-op */ }

require __DIR__ . '/../../application/helpers/reengage_helper.php';
require __DIR__ . '/../../application/helpers/channel_policy_helper.php';
require __DIR__ . '/../../application/helpers/inbox_helper.php';
require __DIR__ . '/../../application/helpers/conversation_helper.php';
require __DIR__ . '/../../application/helpers/inbox_realtime_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ------------------------------------------------------------------------------------------
// A fake CodeIgniter query builder: records every call so a test can assert the tenant guard
// (user_id) is ALWAYS the query's first condition, on every filter combination — the actual
// shape IDOR bugs in this codebase have taken before (brief §38/§57). Deliberately NOT a mock
// of behaviour we then assert was "called" for its own sake: it records the SQL SHAPE that
// would run, which is the thing under test.
class FakeQB
{
    public $calls = array();
    public function where($a, $b = null, $escape = null) { $this->calls[] = array('where', $a, $b); return $this; }
    public function or_where($a, $b = null, $escape = null) { $this->calls[] = $b === null ? array('or_where', $a) : array('or_where', $a, $b); return $this; }
    public function like($a, $b) { $this->calls[] = array('like', $a, $b); return $this; }
    public function or_like($a, $b) { $this->calls[] = array('or_like', $a, $b); return $this; }
    public function group_start() { $this->calls[] = array('group_start'); return $this; }
    public function group_end() { $this->calls[] = array('group_end'); return $this; }
}

// ============================================================================================
echo "== tenant isolation in query builders (fakes) ==\n";

$q = new FakeQB();
inbox_scope_conversations($q, 4321, array());
check('conversations list: first call is the tenant guard', $q->calls[0], array('where', 'c.user_id', 4321));
check('conversations list: no filters -> exactly one where() call (just the guard)', count($q->calls), 1);

$q = new FakeQB();
inbox_scope_conversations($q, 4321, array('platform' => 'wa', 'status' => 'open', 'handoff_state' => 'HUMAN_ACTIVE',
    'assigned_user_id' => 7, 'unread_only' => true, 'search' => "o'brien"));
check('conversations list: tenant guard still first with every filter set', $q->calls[0], array('where', 'c.user_id', 4321));
$has_user_filter_bypass = false;
foreach ($q->calls as $c) { if ($c[0] === 'where' && $c[1] === 'c.user_id' && $c[2] !== 4321) $has_user_filter_bypass = true; }
check('conversations list: user_id is never overwritten by a filter', $has_user_filter_bypass, false);
$search_call = null;
foreach ($q->calls as $c) if ($c[0] === 'or_where') $search_call = $c;
check('conversations list: search input with a quote is escaped, not interpolated raw',
    $search_call !== null && strpos($search_call[1], "o'brien") === false, true);

// A filter dict crafted to try to smuggle a different tenant id through assigned_user_id
// or any other key must still leave the guard as user_id, not something attacker-controlled.
$q = new FakeQB();
inbox_scope_conversations($q, 99, array('user_id' => 1)); // 'user_id' is not a recognised filter key
check('conversations list: an unrecognised "user_id" filter key cannot override the guard', $q->calls[0], array('where', 'c.user_id', 99));

$q = new FakeQB();
inbox_scope_conversation_owner($q, 555, 99);
check('single conversation: id AND user_id both applied', $q->calls, array(array('where', 'id', 555), array('where', 'user_id', 99)));

// ============================================================================================
echo "\n== policy-denied reply path ==\n";

$now = strtotime('2026-09-23 12:00:00');
$h = function ($hours) use ($now) { return date('Y-m-d H:i:s', $now - $hours * 3600); };

$d = inbox_reply_decision('wa_cloud', $h(30), false, $now); // outside 24h window, WhatsApp Cloud needs a template
check('wa_cloud reply 30h after last inbound: denied', $d['allowed'], false);
check('wa_cloud reply 30h after last inbound: reason', $d['reason'], 'template_required_outside_24h');
check('wa_cloud reply 30h after last inbound: owner-language explanation present, not the raw code', strpos($d['explain'], 'WhatsApp only allows an approved template') !== false, true);

$d2 = inbox_reply_decision('wa_cloud', $h(2), false, $now);
check('wa_cloud reply 2h after last inbound: allowed', $d2['allowed'], true);

// OpenWA's never_contacted_us guard only gates AUTOMATION (channel_policy_helper.php: the ban
// risk is unsolicited automated outreach); a human agent may always use the session channel.
$d3 = inbox_reply_decision('wa', null, false, $now);
check('OpenWA human reply with no prior inbound: still allowed (only automation is restricted)', $d3['allowed'], true);
check('OpenWA human reply with no prior inbound: reason', $d3['reason'], 'session_channel');

// Telegram DOES gate on it regardless of sender (the bot API rejects messaging a chat_id
// that never started the bot), so a human inbox reply is denied there with no prior inbound.
$d3b = inbox_reply_decision('tg', null, false, $now);
check('Telegram human reply with no prior inbound: denied (bot API requires the user started the chat)', $d3b['allowed'], false);
check('Telegram human reply with no prior inbound: reason', $d3b['reason'], 'user_never_started_bot');

$d4 = inbox_reply_decision('fb', $h(30), false, $now); // fb human reply after 24h: HUMAN_AGENT tag still allowed
check('fb human reply 30h after last inbound: still allowed (HUMAN_AGENT tag, brief exemption)', $d4['allowed'], true);
check('fb human reply 30h after last inbound: tag', $d4['tag'], 'HUMAN_AGENT');

$d5 = inbox_reply_decision('fb', $h(200), false, $now); // outside 7 days: nobody may send
check('fb human reply 200h (>7d) after last inbound: denied', $d5['allowed'], false);

check('opted_out never affects a human-sender decision (channel_policy only gates automation on it)',
    inbox_reply_decision('fb', $h(1), true, $now)['allowed'], true);

check('wa_openwa is mapped to the policy engine key "wa" (its adapter registry key differs)',
    inbox_platform_to_policy_key('wa', 'wa_openwa'), 'wa');
check('wa_cloud is mapped to its own policy engine key', inbox_platform_to_policy_key('wa', 'wa_cloud'), 'wa_cloud');
check('non-wa platforms pass through unchanged', inbox_platform_to_policy_key('tg'), 'tg');

// ============================================================================================
echo "\n== transition wiring (inbox_helper action mapping vs the real state machine) ==\n";

foreach (inbox_status_action_map() as $action => $to) {
    if (!in_array($to, conversation_states(), true)) bad("action '$action' maps to a real state", $to);
    else ok("action '$action' maps to a real state ($to)");
}

// Every hop inbox_takeover_path() proposes must be a transition conversation_transition_allowed()
// actually permits for actor_type='agent' — otherwise "Take over from AI" would silently fail
// midway (conversation_transition() itself refuses and rolls back on the first disallowed hop).
foreach (array('AI_ACTIVE', 'WAITING_FOR_HUMAN', 'AI_ASSIST', 'RESOLVED', 'HUMAN_ACTIVE') as $from) {
    $path = inbox_takeover_path($from);
    $cur = $from;
    $all_ok = true;
    foreach ($path as $to) {
        if (!conversation_transition_allowed($cur, $to, 'agent')) { $all_ok = false; break; }
        $cur = $to;
    }
    if ($all_ok && ($path === array() ? $from === 'HUMAN_ACTIVE' : $cur === 'HUMAN_ACTIVE')) {
        ok("takeover path from $from reaches HUMAN_ACTIVE via allowed hops only (" . implode(' -> ', $path ?: array('(already there)')) . ')');
    } else {
        bad("takeover path from $from reaches HUMAN_ACTIVE via allowed hops only", implode(' -> ', $path));
    }
}

foreach (array('AI_ACTIVE', 'WAITING_FOR_HUMAN', 'AI_ASSIST', 'RESOLVED', 'HUMAN_ACTIVE') as $from) {
    $path = inbox_resolve_path($from);
    $cur = $from;
    $all_ok = true;
    foreach ($path as $to) {
        if (!conversation_transition_allowed($cur, $to, 'agent')) { $all_ok = false; break; }
        $cur = $to;
    }
    if ($all_ok && $cur === 'RESOLVED') ok("resolve path from $from reaches RESOLVED via allowed hops only (" . implode(' -> ', $path) . ')');
    else bad("resolve path from $from reaches RESOLVED via allowed hops only", implode(' -> ', $path));
}

// A single AI_ACTIVE -> HUMAN_ACTIVE hop does NOT exist in the brief §14 state machine
// (conversation_transition_map in conversation_helper.php) — this is exactly why
// inbox_takeover_path() must route through WAITING_FOR_HUMAN. Pin that fact so a future edit
// to either map cannot silently desync them.
check('AI_ACTIVE -> HUMAN_ACTIVE has no direct edge (this is why the takeover path has 2 hops)',
    conversation_transition_allowed('AI_ACTIVE', 'HUMAN_ACTIVE', 'agent'), false);
check('takeover path from AI_ACTIVE is exactly the documented 2 hops',
    inbox_takeover_path('AI_ACTIVE'), array('WAITING_FOR_HUMAN', 'HUMAN_ACTIVE'));

// return_to_bot / reopen: any state -> AI_ACTIVE, agent-only special case
foreach (conversation_states() as $from) {
    check("agent can return '$from' conversation to the bot (any state -> AI_ACTIVE)",
        conversation_transition_allowed($from, 'AI_ACTIVE', 'agent'), true);
}
// The "any state -> AI_ACTIVE" rule is an agent-only special case (conversation_transition_allowed);
// a non-agent actor (system/ai/customer) can only take the map edges, and HUMAN_ACTIVE has none
// to AI_ACTIVE. This is why the inbox's return_to_bot() always passes actor_type='agent'.
check('HUMAN_ACTIVE -> AI_ACTIVE for a non-agent actor is not allowed (no map edge, no special case)',
    conversation_transition_allowed('HUMAN_ACTIVE', 'AI_ACTIVE', 'system'), false);
check('HUMAN_ACTIVE -> AI_ACTIVE for an agent IS allowed (the special case)',
    conversation_transition_allowed('HUMAN_ACTIVE', 'AI_ACTIVE', 'agent'), true);

// ============================================================================================
echo "\n== owner-language labels (brief §46: no bot_paused_until / XeroChat jargon in the UI) ==\n";
foreach (conversation_states() as $s) {
    $label = inbox_handoff_label($s);
    check("handoff label for $s is not the raw state name", $label === $s, false);
}
check('AI_ACTIVE label', inbox_handoff_label('AI_ACTIVE'), 'AI is replying');
check('WAITING_FOR_HUMAN label', inbox_handoff_label('WAITING_FOR_HUMAN'), 'Waiting for a teammate');
check('HUMAN_ACTIVE label', inbox_handoff_label('HUMAN_ACTIVE'), "You're handling this");
check('AI_ASSIST label', inbox_handoff_label('AI_ASSIST'), 'AI is helping you');
check('RESOLVED label', inbox_handoff_label('RESOLVED'), 'Closed');

// ============================================================================================
echo "\n== labels (channel-agnostic, keyed to conversations) ==\n";

$q = new FakeQB();
inbox_scope_conversations($q, 4321, array('label_id' => 17));
check('label filter: tenant guard is still call #1', $q->calls[0], array('where', 'c.user_id', 4321));
check('label filter: exactly one extra condition', count($q->calls), 2);
check('label filter: EXISTS on conversation_labels, scoped to the SAME tenant and the label id',
    $q->calls[1][1], 'EXISTS (SELECT 1 FROM conversation_labels cl WHERE cl.conversation_id = c.id AND cl.user_id = 4321 AND cl.label_id = 17)');

$q = new FakeQB();
inbox_scope_conversations($q, 4321, array('label_id' => '5 OR 1=1'));
check('label filter: a crafted label id is cast to int, never interpolated raw',
    strpos($q->calls[1][1], 'OR 1=1') === false && strpos($q->calls[1][1], 'cl.label_id = 5)') !== false, true);

foreach (array(0, '', null, -3, 'abc') as $v) {
    $q = new FakeQB();
    inbox_scope_conversations($q, 4321, array('label_id' => $v));
    check('label filter: ' . var_export($v, true) . ' adds no condition', count($q->calls), 1);
}

$q = new FakeQB();
inbox_scope_labels($q, 77, 5);
check('single label: tenant first, then id', $q->calls, array(array('where', 'user_id', 77), array('where', 'id', 5)));
$q = new FakeQB();
inbox_scope_labels($q, 77);
check('label list: tenant only', $q->calls, array(array('where', 'user_id', 77)));

check('label name: trimmed and whitespace collapsed', inbox_label_normalize_name("  VIP   customer \n"), 'VIP customer');
check('label name: tags stripped (stored text never carries markup)', inbox_label_normalize_name('<b>Hot</b><script>x</script>'), 'Hotx');
check('label name: Arabic kept as-is', inbox_label_normalize_name('عميل مهم'), 'عميل مهم');
check('label name: capped at 60 characters (multibyte-safe)', mb_strlen(inbox_label_normalize_name(str_repeat('ح', 90))), 60);
check('label name: empty -> NULL', inbox_label_normalize_name('   '), null);
check('label name: non-string -> NULL', inbox_label_normalize_name(array('x')), null);
check('label colour: #RRGGBB lower-cased', inbox_label_normalize_color('#AABBCC'), '#aabbcc');
check('label colour: missing # is added', inbox_label_normalize_color('00ff00'), '#00ff00');
check('label colour: CSS injection attempt dropped', inbox_label_normalize_color('red;background:url(x)'), null);
check('label colour: short form rejected', inbox_label_normalize_color('#fff'), null);
check('label colour: empty -> NULL', inbox_label_normalize_color(''), null);

// ============================================================================================
echo "\n== canned responses filtered by channel ==\n";

check('channels: NULL stays NULL (every pre-existing row = all channels)', inbox_canned_channels_normalize(null), null);
check('channels: empty string -> NULL', inbox_canned_channels_normalize(''), null);
check("channels: 'all' -> NULL", inbox_canned_channels_normalize('all'), null);
check("channels: array('all') (the UI's 'All channels') -> NULL", inbox_canned_channels_normalize(array('all')), null);
check('channels: stable order, deduplicated', inbox_canned_channels_normalize(array('tg', 'wa', 'tg', 'FB')), 'fb,wa,tg');
check('channels: CSV input accepted', inbox_canned_channels_normalize('web, ig'), 'ig,web');
check('channels: unknown values dropped', inbox_canned_channels_normalize(array('wa', "x');DROP TABLE y;--", 'sms')), 'wa');
check('channels: only unknown values -> NULL (all), never an empty filter that hides the row', inbox_canned_channels_normalize(array('sms')), null);
check('channels: every platform chosen -> NULL (one spelling for "all")', inbox_canned_channels_normalize(array('fb', 'ig', 'wa', 'tg', 'web')), null);
check('channels: fits the varchar(64) column', strlen('fb,ig,wa,tg,web') <= 64, true);

foreach (array('fb', 'ig', 'wa', 'tg', 'web') as $p) {
    check("visible: NULL channels shown on $p (existing rows keep showing everywhere)", inbox_canned_visible_on(null, $p), true);
}
check("visible: '' shown everywhere", inbox_canned_visible_on('', 'tg'), true);
check("visible: 'wa,tg' shown on wa", inbox_canned_visible_on('wa,tg', 'wa'), true);
check("visible: 'wa,tg' hidden on fb", inbox_canned_visible_on('wa,tg', 'fb'), false);
check("visible: 'fb' does not match 'fbx' style prefixes", inbox_canned_visible_on('fb', 'f'), false);

$q = new FakeQB();
inbox_scope_canned($q, 55, 'wa');
check('canned scope: tenant guard is call #1', $q->calls[0], array('where', 'user_id', 55));
check('canned scope: NULL channels always included', in_array(array('where', 'channels IS NULL', null), $q->calls, true), true);
check('canned scope: FIND_IN_SET on the conversation platform',
    in_array(array('or_where', "FIND_IN_SET('wa', channels) > 0"), $q->calls, true), true);
check('canned scope: grouped so OR cannot escape the tenant guard',
    $q->calls[1] === array('group_start') && end($q->calls) === array('group_end'), true);

$q = new FakeQB();
inbox_scope_canned($q, 55, "wa') OR 1=1 --");
$raw = '';
foreach ($q->calls as $c) if ($c[0] === 'or_where') $raw .= $c[1];
check('canned scope: an unknown platform is never interpolated', strpos($raw, 'OR 1=1') === false, true);

$q = new FakeQB();
inbox_scope_canned($q, 55, null);
check('canned scope: no conversation -> tenant rows only, no channel filter', $q->calls, array(array('where', 'user_id', 55)));

$q = new FakeQB();
inbox_scope_canned($q, 55, 'tg', false);
check('canned scope: before the migration (no channels column) -> unfiltered as before', $q->calls, array(array('where', 'user_id', 55)));

// ============================================================================================
echo "\n== attachments: rendering what the stored row already has ==\n";

$r = inbox_message_render('Hello there');
check('plain text renders as text, no attachments', array($r['text'], $r['attachments'], $r['media_unavailable']), array('Hello there', array(), false));

$fb_in = json_encode(array('object' => 'page', 'entry' => array(array('id' => 'P1', 'messaging' => array(array(
    'sender' => array('id' => 'S1'), 'recipient' => array('id' => 'P1'),
    'message' => array('mid' => 'm1', 'attachments' => array(
        array('type' => 'image', 'payload' => array('url' => 'https://scontent.xx.fbcdn.net/v/t1/abc.jpg?x=1')),
        array('type' => 'audio', 'payload' => array('url' => 'https://cdn.fbsbx.com/v/t59/voice.mp4')),
    )),
))))));
$r = inbox_message_render($fb_in);
check('fb inbound webhook body: image + audio URLs extracted', $r['attachments'], array(
    array('type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t1/abc.jpg?x=1'),
    array('type' => 'audio', 'url' => 'https://cdn.fbsbx.com/v/t59/voice.mp4')));
check('fb inbound webhook body: no raw JSON shown as text', $r['text'], '');

$ig_in = json_encode(array('object' => 'instagram', 'entry' => array(array('messaging' => array(array(
    'sender' => array('id' => 'S1'), 'message' => array('mid' => 'm2', 'text' => 'شوف الصورة'),
))))));
check('ig inbound text body: text extracted', inbox_message_render($ig_in)['text'], 'شوف الصورة');

$evil = json_encode(array('object' => 'page', 'entry' => array(array('messaging' => array(array(
    'message' => array('attachments' => array(
        array('type' => 'image', 'payload' => array('url' => 'javascript:alert(1)')),
        array('type' => 'file', 'payload' => array('url' => 'data:text/html,<script>alert(1)</script>')),
        array('type' => 'image', 'payload' => array('url' => '//evil.example/x.png')),
    )),
))))));
$r = inbox_message_render($evil);
check('javascript:, data: and scheme-relative URLs are never rendered', $r['attachments'], array());
check('...and the bubble says media is unavailable instead of showing raw JSON', array($r['text'], $r['media_unavailable']), array('', true));

$fallback = json_encode(array('object' => 'page', 'entry' => array(array('messaging' => array(array(
    'message' => array('attachments' => array(array('type' => 'fallback', 'payload' => array('url' => 'https://example.com/share')))),
))))));
check('unknown attachment type (fallback/share) is not rendered as media', inbox_message_render($fallback)['attachments'], array());

$sent_img = inbox_image_message_content('7101234567890123', 'https://app.example.com/upload/inbox/2026/09/inbox11_ab.jpg');
check('image reply content: Send API shape (what Livechat renders for its own media sends)', json_decode($sent_img, true), array(
    'recipient' => array('id' => '7101234567890123'),
    'message' => array('attachment' => array('type' => 'image', 'payload' => array('url' => 'https://app.example.com/upload/inbox/2026/09/inbox11_ab.jpg', 'is_reusable' => false)))));
check('image reply content renders as an image in the inbox', inbox_message_render($sent_img)['attachments'],
    array(array('type' => 'image', 'url' => 'https://app.example.com/upload/inbox/2026/09/inbox11_ab.jpg')));

$sent_text = json_encode(array('recipient' => array('id' => 'S1'), 'message' => array('text' => 'Bot reply', 'quick_replies' => array())));
check('Send API text body (fb bot rows) renders its text, not raw JSON', inbox_message_render($sent_text)['text'], 'Bot reply');
$tpl = json_encode(array('recipient' => array('id' => 'S1'), 'message' => array('attachment' => array('type' => 'template', 'payload' => array('template_type' => 'button', 'text' => 'Choose one')))));
check('button template renders its text', inbox_message_render($tpl)['text'], 'Choose one');

foreach (array('[non-text message]', '[non-text]') as $ph) {
    $r = inbox_message_render($ph);
    check("'$ph' (wa/tg/openwa log no URL) -> media unavailable, no fake attachment", array($r['text'], $r['attachments'], $r['media_unavailable']), array('', array(), true));
}
check('other JSON is shown as stored (as before)', inbox_message_render('{"foo":1}')['text'], '{"foo":1}');
check('broken JSON is shown as stored', inbox_message_render('{not json')['text'], '{not json');

check('preview: image-only message', inbox_message_preview($sent_img), '[image]');
check('preview: fb webhook body text', inbox_message_preview($ig_in), 'شوف الصورة');
check('preview: wa media placeholder', inbox_message_preview('[non-text message]'), '[media]');
check('preview: tags stripped, 120 chars max', inbox_message_preview('<b>' . str_repeat('x', 200) . '</b>'), str_repeat('x', 120));
check('preview: NULL (no messages) -> empty', inbox_message_preview(null), '');

echo "\n== attachments: upload validation (bytes, not names) ==\n";

$tmpdir = sys_get_temp_dir() . '/inbox_upload_test_' . getmypid();
@mkdir($tmpdir);
$img = imagecreatetruecolor(8, 6);
imagepng($img, "$tmpdir/ok.png");
imagejpeg($img, "$tmpdir/ok.jpg");
imagegif($img, "$tmpdir/x.gif");
file_put_contents("$tmpdir/shell.jpg", "<?php system(\$_GET['c']); ?>");
file_put_contents("$tmpdir/poly.jpg", file_get_contents("$tmpdir/ok.jpg") . "<?php echo 1; ?>");
file_put_contents("$tmpdir/x.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
file_put_contents("$tmpdir/empty.png", '');

$v = inbox_image_upload_validate("$tmpdir/ok.png", filesize("$tmpdir/ok.png"));
check('real PNG accepted, extension from the bytes', array($v['ok'], $v['ext'], $v['mime']), array(true, 'png', 'image/png'));
$v = inbox_image_upload_validate("$tmpdir/ok.jpg", filesize("$tmpdir/ok.jpg"));
check('real JPEG accepted as .jpg', array($v['ok'], $v['ext']), array(true, 'jpg'));
check('GIF refused (not every channel accepts it)', inbox_image_upload_validate("$tmpdir/x.gif", filesize("$tmpdir/x.gif"))['ok'], false);
check('PHP script named .jpg refused', inbox_image_upload_validate("$tmpdir/shell.jpg", filesize("$tmpdir/shell.jpg"))['ok'], false);
check('JPEG with appended PHP (polyglot) refused', inbox_image_upload_validate("$tmpdir/poly.jpg", filesize("$tmpdir/poly.jpg"))['ok'], false);
check('SVG refused (scriptable)', inbox_image_upload_validate("$tmpdir/x.svg", filesize("$tmpdir/x.svg"))['ok'], false);
check('empty file refused', inbox_image_upload_validate("$tmpdir/empty.png", 0)['ok'], false);
check('over the size limit refused', inbox_image_upload_validate("$tmpdir/ok.png", filesize("$tmpdir/ok.png"), 0, 10)['ok'], false);
check('PHP upload error INI_SIZE -> "larger than 5 MB"', inbox_image_upload_validate("$tmpdir/ok.png", 1, UPLOAD_ERR_INI_SIZE)['error'], 'The image is larger than 5 MB');
check('partial upload refused', inbox_image_upload_validate("$tmpdir/ok.png", 1, UPLOAD_ERR_PARTIAL)['ok'], false);
check('missing file refused', inbox_image_upload_validate("$tmpdir/nope.png", 10)['ok'], false);
check('limit is 5 MB', inbox_image_max_bytes(), 5242880);
array_map('unlink', glob("$tmpdir/*")); @rmdir($tmpdir);

$name = inbox_image_storage_name(11, 'png', 'ABCDEF0123zz../', strtotime('2026-09-30 10:00:00'));
check('stored under upload/inbox/YYYY/MM with a tenant prefix and a hex-only random part', $name, 'upload/inbox/2026/09/inbox11_abcdef0123.png');
check('storage extension can only be jpg or png', inbox_image_storage_name(11, 'php', 'aa', 0), 'upload/inbox/1970/01/inbox11_aa.jpg');
check('media URL filter: https kept', inbox_safe_media_url('https://x.example/a.jpg'), 'https://x.example/a.jpg');
check('media URL filter: ftp dropped', inbox_safe_media_url('ftp://x.example/a.jpg'), null);
check('media URL filter: non-string dropped', inbox_safe_media_url(array('https://x')), null);

// ============================================================================================
echo "\n== AI state keyed to conversations (ai_conversation_history.page_id per channel) ==\n";

check('fb: page_id is the Facebook page id', conversation_ai_history_lookup('fb', '104857600000001'), array('platform' => 'fb', 'mode' => 'fb_page', 'value' => '104857600000001'));
check('ig: page_id is the Facebook page id', conversation_ai_history_lookup('ig', '104857600000001')['mode'], 'fb_page');
check('tg: page_id is telegram_accounts.id', conversation_ai_history_lookup('tg', '9303'), array('platform' => 'tg', 'mode' => 'account_id', 'value' => '9303'));
check('tg: a non-numeric page_id resolves nothing', conversation_ai_history_lookup('tg', 'abc'), null);
check('wa: Cloud account id OR OpenWA session id (both writers use social_media=wa)', conversation_ai_history_lookup('wa', 'sess-a')['mode'], 'wa_account_or_session');
check('web: every web conversation has channel_account_id 0, whatever the widget key', conversation_ai_history_lookup('web', 'wk_3f9a'), array('platform' => 'web', 'mode' => 'fixed', 'value' => '0'));
check('empty page_id (comment replies without a page) resolves nothing', conversation_ai_history_lookup('fb', ''), null);
check('unknown channel resolves nothing', conversation_ai_history_lookup('tiktok', '1'), null);


// ============================================================================================
echo "\n== realtime (Pusher): settings, channel auth tenant isolation, payload, publish safety ==\n";

$cfg_on = array('pusher_app_id' => '1', 'pusher_app_key' => 'k3y', 'pusher_app_secret' => 's3cret', 'pusher_cluster' => 'eu', 'encryption_key' => 'install-A');
check('realtime off when Pusher is not configured (stock config/pusher.php: empty strings)',
    inbox_realtime_settings(array('pusher_app_id' => '', 'pusher_app_key' => '', 'pusher_app_secret' => '', 'pusher_cluster' => '')), null);
check('realtime off when only the key is set (publishing would fail; the page must not try)', inbox_realtime_settings(array('pusher_app_key' => 'k')), null);
check('realtime off with the kill switch inbox_realtime_enabled === FALSE', inbox_realtime_settings($cfg_on + array('inbox_realtime_enabled' => false)), null);
$S = inbox_realtime_settings($cfg_on);
check('realtime on when app id + key + secret are set', is_array($S) && $S['key'] === 'k3y' && $S['cluster'] === 'eu', true);
check('publish timeout defaults to 2s', $S['timeout'], 2.0);
check('publish timeout is clamped (a 30s setting cannot stall a webhook)', inbox_realtime_settings($cfg_on + array('inbox_realtime_timeout' => 30))['timeout'], 2.0);
check('publish timeout honours a short setting', inbox_realtime_settings($cfg_on + array('inbox_realtime_timeout' => '0.8'))['timeout'], 0.8);
check('namespace differs per install (two installs sharing one Pusher app never share channels)',
    inbox_realtime_settings(array('encryption_key' => 'install-B') + $cfg_on)['ns'] !== $S['ns'], true);

$chA = inbox_realtime_channel_name(7, $S['ns']); $chB = inbox_realtime_channel_name(8, $S['ns']);
check('channel is private and per tenant', strpos($chA, 'private-inbox-') === 0 && $chA !== $chB, true);
check('channel name only carries Pusher-safe characters', preg_match('/^[A-Za-z0-9_\-=@,.;]+$/', inbox_realtime_channel_name(7, "a b/<x>")) === 1, true);

// signature: the Pusher documentation's own worked example (pusher.com/docs/channels/library_auth_reference/auth-signatures)
check('signature matches the Pusher auth-signature reference vector',
    inbox_realtime_sign('278d425bdf160c739803', '7ad3773142a6692b25b8', '1234.1234', 'private-foobar'),
    '278d425bdf160c739803:58df8b0c36d6982b82c3ecf6b4662e34fe8c25bba48f5369f135bf843651c3a4');

$a = inbox_realtime_authorize(7, $chA, '123.456', $S);
check('tenant 7 may subscribe to its own channel', is_array($a) && $a['auth'] === inbox_realtime_sign('k3y', 's3cret', '123.456', $chA), true);
check('tenant 7 may NOT subscribe to tenant 8\'s channel', inbox_realtime_authorize(7, $chB, '123.456', $S), null);
check('tenant 8 may NOT subscribe to tenant 7\'s channel', inbox_realtime_authorize(8, $chA, '123.456', $S), null);
check('another install\'s channel for the same user id is refused', inbox_realtime_authorize(7, inbox_realtime_channel_name(7, 'otherns'), '123.456', $S), null);
check('the public Livechat channel is refused', inbox_realtime_authorize(7, 'livechat_channel', '123.456', $S), null);
check('a presence- twin of the own channel is refused', inbox_realtime_authorize(7, str_replace('private-', 'presence-', $chA), '123.456', $S), null);
check('own channel with a suffix is refused (no prefix match)', inbox_realtime_authorize(7, $chA . '0', '123.456', $S), null);
check('tenant 70 cannot use tenant 7\'s channel and vice versa', inbox_realtime_authorize(70, $chA, '123.456', $S), null);
check('no tenant (0) is refused', inbox_realtime_authorize(0, inbox_realtime_channel_name(0, $S['ns']), '123.456', $S), null);
check('malformed socket id is refused (no signing of attacker-shaped strings)', inbox_realtime_authorize(7, $chA, '123.456:' . $chB, $S), null);
check('empty socket id is refused', inbox_realtime_authorize(7, $chA, '', $S), null);
check('realtime off -> nothing is authorized', inbox_realtime_authorize(7, $chA, '123.456', null), null);
check('array input cannot confuse the check', inbox_realtime_authorize(7, array($chA), '123.456', $S), null);

// REST publish request: vector cross-checked once against vendor/pusher 7.2.6
// (Pusher::build_auth_query_params + make_trigger_batch_body give the identical body and signature).
$req = inbox_realtime_batch_request(array('app_id' => '42', 'key' => 'k3y', 'secret' => 's3cret', 'cluster' => 'eu', 'host' => '', 'port' => 0, 'scheme' => ''),
    array(array('channel' => 'private-inbox-abc-7', 'name' => 'message', 'data' => array('conversation_id' => 41, 'message_id' => 101, 'sender' => 'bot'))), 1700000000);
check('publish body is the Pusher batch_events shape', $req['body'],
    '{"batch":[{"name":"message","data":"{\\"conversation_id\\":41,\\"message_id\\":101,\\"sender\\":\\"bot\\"}","channel":"private-inbox-abc-7"}]}');
check('publish URL: cluster host, signed exactly like the Pusher library', $req['url'],
    'https://api-eu.pusher.com:443/apps/42/batch_events?auth_key=k3y&auth_timestamp=1700000000&auth_version=1.0&body_md5=07fe1c97738a1fdf5affb49bfc0db1de&auth_signature=1752e6428a701b2e8922b06f9ecd3e9f70fd80d966596139b33597b2ab0a2ae0');
$req2 = inbox_realtime_batch_request(array('app_id' => '1', 'key' => 'k', 'secret' => 's', 'cluster' => '', 'host' => 'fakepusher', 'port' => 8080, 'scheme' => 'http'), array(), 1);
check('publish URL honours pusher_host/port/scheme (self-hosted or test endpoint)', strpos($req2['url'], 'http://fakepusher:8080/apps/1/batch_events?'), 0);

$p = inbox_realtime_event_payload('41', '9001', 'user');
check('event payload is ids + direction only (no message content)', array_keys($p), array('conversation_id', 'message_id', 'sender'));
check('unknown sender normalized', inbox_realtime_event_payload(1, 2, '<b>')['sender'], 'user');

$queue = array(
    array('user_id' => 7, 'conversation_id' => 41, 'message_id' => 100, 'sender' => 'user', 'message_content' => 'secret text'),
    array('user_id' => 7, 'conversation_id' => 41, 'message_id' => 101, 'sender' => 'bot'),
    array('user_id' => 8, 'conversation_id' => 55, 'message_id' => 102, 'sender' => 'user'),
    array('user_id' => 8, 'conversation_id' => 0, 'message_id' => 103, 'sender' => 'user'),
);
$batches = inbox_realtime_batch($queue, $S['ns']);
check('one event per conversation; unlinked rows (conversation 0) skipped', count($batches[0]), 2);
check('conversation 41 goes to tenant 7\'s channel only, newest message wins', $batches[0][0], array('channel' => $chA, 'name' => 'message',
    'data' => array('conversation_id' => 41, 'message_id' => 101, 'sender' => 'bot')));
check('conversation 55 goes to tenant 8\'s channel only', $batches[0][1]['channel'], $chB);
check('no event anywhere carries message content', strpos(json_encode($batches), 'secret text'), false);
$many = array(); for ($i = 1; $i <= 23; $i++) $many[] = array('user_id' => 7, 'conversation_id' => $i, 'message_id' => $i, 'sender' => 'user');
check('batches are chunked at 10 (Pusher batch limit)', array_map('count', inbox_realtime_batch($many, $S['ns'])), array(10, 10, 3));

// publish safety: flush never throws, and after one failure stops trying for the request
$st = &inbox_realtime_queue();
$sent = array();
$st['transport'] = function ($b) use (&$sent) { $sent[] = $b; };
$st['queue'] = $queue;
inbox_realtime_flush($S);
check('flush sends the batch through the transport and empties the queue', count($sent) === 1 && $st['queue'] === array(), true);
$st['transport'] = function ($b) { throw new RuntimeException('pusher timed out'); };
$st['queue'] = $queue;
$threw = false; try { inbox_realtime_flush($S); } catch (\Throwable $e) { $threw = true; }
check('a Pusher failure/timeout never reaches the caller', $threw, false);
check('...and marks realtime broken for the rest of the request', $st['broken'], true);
$calls = 0; $st['transport'] = function ($b) use (&$calls) { $calls++; };
$st['queue'] = $queue; inbox_realtime_flush($S);
check('...so later messages in the same request do not wait on Pusher again', $calls, 0);
$st['broken'] = false; $st['transport'] = null; $st['queue'] = array();
$st['queue'] = $queue; inbox_realtime_flush(null);
check('flush with realtime off is a no-op', $st['queue'], array());

check('poll intervals default to 20s (no realtime) / 90s (realtime connected)', inbox_realtime_poll_intervals(null, null), array(20, 90));
check('poll intervals have floors (5s / 15s)', inbox_realtime_poll_intervals(1, 2), array(5, 15));

// wiring (static): every place that writes livechat_messages publishes right after the insert,
// so no channel (fb, ig, wa cloud, openwa, tg, web, inbox replies, system rows) is left polling-only.
$root = dirname(__DIR__, 2);
$sites = 0; $unwired = array();
foreach (array_merge(glob($root . '/application/controllers/*.php'), glob($root . '/application/helpers/*.php')) as $f) {
    $lines = file($f);
    foreach ($lines as $n => $line) {
        if (!preg_match('/insert(_data)?\(\s*[\'"]livechat_messages[\'"]/', $line)) continue;
        $sites++;
        $window = implode("", array_slice($lines, $n, 20));
        if (strpos($window, 'inbox_realtime_notify(') === false) $unwired[] = basename($f) . ':' . ($n + 1);
    }
}
check('found every livechat_messages writer (9 today)', $sites, 9);
check('every livechat_messages insert is followed by inbox_realtime_notify()', $unwired, array());
$inbox = file_get_contents($root . '/application/controllers/Inbox.php');
check('Inbox::realtime_auth checks CSRF and authorizes only $this->uid', strpos($inbox, 'csrf_token_check(true)') !== false
    && strpos($inbox, 'inbox_realtime_authorize($this->uid,') !== false, true);

echo "\n== $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);
