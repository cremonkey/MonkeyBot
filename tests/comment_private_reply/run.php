<?php
/**
 * Comment private replies -> Unified Inbox (application/helpers/comment_private_reply_helper.php).
 * No DB, no CodeIgniter bootstrap, no network. A small fake query builder stands in for CI's
 * $this->db so cpr_flush() runs end to end: dedupe, insert, conversation read-back, note, and
 * the "never throws / db_debug restored" guarantees. Static wiring checks pin the controller
 * and library call sites so the hooks cannot silently disappear.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/comment_private_reply/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
$GLOBALS['__log'] = array();
function log_message($level, $msg) { $GLOBALS['__log'][] = array($level, $msg); }

require __DIR__ . '/../../application/helpers/comment_private_reply_helper.php';
require __DIR__ . '/../../application/helpers/inbox_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ---- fake CI db ----------------------------------------------------------------------------
class FakeResult { private $r; function __construct($r) { $this->r = $r; } function row_array() { return $this->r; } }
class FakeDB
{
    public $db_debug = TRUE;
    public $rows = array('livechat_messages' => array(), 'internal_notes' => array());
    public $fail_insert = array();   // table => true: insert returns false
    public $throw_on = array();      // table => true: insert throws (PHP 8.1 mysqli behaviour)
    public $debug_seen = array();
    private $q = array(); private $last_id = 0; private $next = 100;
    function select($c) { $this->q = array('select' => $c); return $this; }
    function from($t) { $this->q['from'] = $t; return $this; }
    function where($k, $v) { $this->q['where'][$k] = $v; return $this; }
    function limit($n) { return $this; }
    function reset_query() { $this->q = array(); }
    function get() {
        $this->debug_seen[] = $this->db_debug;
        $t = $this->q['from']; $w = isset($this->q['where']) ? $this->q['where'] : array(); $this->q = array();
        foreach ($this->rows[$t] as $r) {
            $m = true; foreach ($w as $k => $v) if ((string) $r[$k] !== (string) $v) $m = false;
            if ($m) return new FakeResult($r);
        }
        return new FakeResult(null);
    }
    function insert($t, $row) {
        $this->debug_seen[] = $this->db_debug;
        if (!empty($this->throw_on[$t])) throw new RuntimeException("insert into $t exploded");
        if (!empty($this->fail_insert[$t])) return false;
        $row['id'] = ++$this->next;
        if ($t === 'livechat_messages') $row['conversation_id'] = 7000 + count($this->rows[$t]); // what the trigger would set
        $this->rows[$t][] = $row; $this->last_id = $row['id'];
        return true;
    }
    function insert_id() { return $this->last_id; }
}
class FakeFb { public $calls = 0; public $answer = array('to' => array(array('id' => 'PSID_LOOKUP'))); public $throw = false;
    function get_private_reply_message_id_info($mid, $tok) { $this->calls++; if ($this->throw) throw new Exception('graph down'); return $this->answer; } }
class FakeCI { public $db; public $fb_rx_login; function __construct() { $this->db = new FakeDB(); $this->fb_rx_login = new FakeFb(); } }

function ctx($extra = array()) {
    return array_merge(array('platform' => 'fb', 'user_id' => 5, 'page_table_id' => 12, 'fb_page_id' => '111',
        'token' => 'tok', 'report' => array('comment_id' => '111_222', 'comment_text' => 'price?', 'comment_reply_text' => 'Check your inbox!', 'reply_status_comment' => 'success'),
        'fallback_psid' => ''), $extra);
}
$text_payload = function ($cid, $text) { return json_encode(array('recipient' => array('comment_id' => $cid), 'message' => array('text' => $text))); };

// ============================================================================================
echo "== cpr_capture ==\n";
$c = cpr_capture($text_payload('111_222', 'Hi'), array('recipient_id' => '9001', 'message_id' => 'm_1'));
check('text send captured', $c, array('comment_id' => '111_222', 'message_id' => 'm_1', 'recipient_id' => '9001', 'message' => array('text' => 'Hi')));
check('library shape (id alias, no recipient_id) captured', cpr_capture(array('recipient' => array('comment_id' => 'c'), 'message' => array('text' => 'x')), array('id' => 'm_2'))['message_id'], 'm_2');
check('Graph error -> not captured', cpr_capture($text_payload('c', 'x'), array('error' => array('message' => 'already replied'))), null);
check('no message id -> not captured', cpr_capture($text_payload('c', 'x'), array('recipient_id' => '1')), null);
check('recipient.id (normal DM) -> not captured', cpr_capture(json_encode(array('recipient' => array('id' => '1'), 'message' => array('text' => 'x'))), array('message_id' => 'm')), null);
check('null result (curl failed) -> not captured', cpr_capture($text_payload('c', 'x'), null), null);
check('garbage payload -> not captured', cpr_capture('not json', array('message_id' => 'm')), null);
check('empty message -> not captured', cpr_capture(array('recipient' => array('comment_id' => 'c'), 'message' => array()), array('message_id' => 'm')), null);

echo "\n== buffer ==\n";
cpr_buffer('take');
check('capture hook returns true on success', cpr_buffer_capture($text_payload('c', 'x'), array('message_id' => 'm')), true);
check('capture hook returns false on error', cpr_buffer_capture($text_payload('c', 'x'), array('error' => array())), false);
check('capture hook never throws on odd input', cpr_buffer_capture(new stdClass(), 42), false);
check('take returns the one capture', count(cpr_buffer('take')), 1);
check('take clears', cpr_buffer('take'), array());
for ($i = 0; $i < CPR_MAX_BUFFER + 10; $i++) cpr_buffer_capture($text_payload('c', 'x'), array('message_id' => "m$i"));
check('buffer is bounded', count(cpr_buffer('take')), CPR_MAX_BUFFER);

echo "\n== cpr_resolve_psids ==\n";
$a1 = array('comment_id' => 'A', 'message_id' => 'm1', 'recipient_id' => '', 'message' => array('text' => '1'));
$a2 = array('comment_id' => 'A', 'message_id' => 'm2', 'recipient_id' => 'R_A', 'message' => array('text' => '2'));
$b1 = array('comment_id' => 'B', 'message_id' => 'm3', 'recipient_id' => '', 'message' => array('text' => '3'));
$r = cpr_resolve_psids(array($a1, $a2), 'A', 'FALLBACK', null);
check('recipient_id from any send of the comment wins over the fallback', array($r[0]['psid'], $r[1]['psid']), array('R_A', 'R_A'));
$r = cpr_resolve_psids(array($a1), 'A', 'FALLBACK', null);
check('caller-resolved PSID used when Graph gave no recipient_id', $r[0]['psid'], 'FALLBACK');
$r = cpr_resolve_psids(array($b1), 'A', 'FALLBACK', null);
check('fallback PSID is NOT applied to a different comment', $r, array());
$calls = 0;
$r = cpr_resolve_psids(array($b1), 'A', '', function ($mid) use (&$calls) { $calls++; return 'L_' . $mid; });
check('lookup used as last resort', array($r[0]['psid'], $calls), array('L_m3', 1));
$r = cpr_resolve_psids(array($b1), 'A', '', function () { throw new Exception('boom'); });
check('throwing lookup -> skipped, not thrown', $r, array());
$many = array(); for ($i = 0; $i < 6; $i++) $many[] = array('comment_id' => "C$i", 'message_id' => "x$i", 'recipient_id' => '', 'message' => array('text' => 't'));
$calls = 0;
$r = cpr_resolve_psids($many, '', '', function () use (&$calls) { $calls++; return 'P'; });
check('lookups are capped per flush', array($calls, count($r)), array(CPR_MAX_LOOKUPS, CPR_MAX_LOOKUPS));
$r = cpr_resolve_psids(array($a2, $a2), 'A', '', null);
check('same message id twice -> one row', count($r), 1);
$num = array('comment_id' => '17890000000000001', 'message_id' => 'n1', 'recipient_id' => '', 'message' => array('text' => 't'));
$r = cpr_resolve_psids(array($num), '17890000000000001', 'IGSID', null);
check('numeric IG comment id still matches the fallback comment', $r[0]['psid'] ?? null, 'IGSID');

echo "\n== cpr_livechat_row ==\n";
$row = cpr_livechat_row($a2, 'PSID9', ctx(array('platform' => 'ig')), '2026-09-23 10:00:00');
check('sender bot', $row['sender'], 'bot');
check('platform ig', $row['platform'], 'ig');
check('subscriber_id is the PSID', $row['subscriber_id'], 'PSID9');
check('page_table_id + fb_page_id carried', array($row['page_table_id'], $row['fb_page_id']), array(12, '111'));
check('fb_message_id = Graph message id (idempotency key)', $row['fb_message_id'], 'm2');
check('user_id carried', $row['user_id'], 5);
check('unknown platform falls back to fb', cpr_livechat_row($a2, 'P', ctx(array('platform' => 'x')), 'now')['platform'], 'fb');
check('user_id 0 -> NULL (trigger resolves the page owner)', cpr_livechat_row($a2, 'P', ctx(array('user_id' => 0)), 'now')['user_id'], null);
$body = json_decode($row['message_content'], true);
check('message_content is a Send API body addressed to the PSID', $body['recipient'], array('id' => 'PSID9'));
check('inbox renders the text', inbox_message_render($row['message_content'])['text'], '2');
check('inbox preview', inbox_message_preview($row['message_content']), '2');
$tpl = array('comment_id' => 'A', 'message_id' => 'mt', 'recipient_id' => 'R', 'message' => array('attachment' => array('type' => 'template', 'payload' => array('template_type' => 'button', 'text' => 'Pick one', 'buttons' => array()))));
check('button template renders its text in the inbox', inbox_message_render(cpr_livechat_row($tpl, 'R', ctx(), 'now')['message_content'])['text'], 'Pick one');
$bad = array('comment_id' => 'A', 'message_id' => 'mb', 'recipient_id' => 'R', 'message' => array('text' => "bad \xC3\x28 utf8"));
check('invalid UTF-8 does not make message_content empty', cpr_livechat_row($bad, 'R', ctx(), 'now')['message_content'] !== '', true);

echo "\n== cpr_note_text ==\n";
$n = cpr_note_text('fb', '111_222', ctx()['report']);
check('note names the network and comment', strpos($n, 'Facebook post (comment 111_222)') !== false, true);
check('note quotes the comment', strpos($n, 'Comment: "price?"') !== false, true);
check('note quotes the public reply', strpos($n, 'Public reply: "Check your inbox!"') !== false, true);
$n = cpr_note_text('ig', 'c', array('comment_text' => 'x', 'comment_reply_text' => 'hey', 'reply_status_comment' => 'Some Graph error'));
check('failed public reply is not presented as posted', strpos($n, 'No public reply was posted.') !== false && strpos($n, 'Public reply:') === false, true);
check('ig wording', strpos($n, 'Instagram post') !== false, true);
$n = cpr_note_text('ig', 'c', array('comment_text' => 'x', 'comment_reply_text' => '', 'reply_status_comment' => 'comment deleted'));
check('deleted comment explained', strpos($n, 'deleted by an offensive-word rule') !== false, true);
$n = cpr_note_text('ig', 'c', array('comment_text' => 'x', 'comment_reply_text' => 'hi', 'reply_status_comment' => 'comment hidden'));
check('hidden after reply: reply shown + hidden noted', strpos($n, 'Public reply: "hi"') !== false && strpos($n, 'hidden after the reply') !== false, true);
$n = cpr_note_text('fb', 'c', array('comment_text' => str_repeat('é', 900)));
check('long comment clipped', mb_strlen($n) < 800, true);

echo "\n== cpr_flush (fake DB) ==\n";
cpr_buffer('take');
$ci = new FakeCI();
cpr_buffer_capture($text_payload('111_222', 'Thanks! Here is the price.'), array('recipient_id' => 'PSID1', 'message_id' => 'm_a'));
check('flush writes one row', cpr_flush($ci, ctx()), 1);
$lm = $ci->db->rows['livechat_messages'];
check('row stored for the PSID', array($lm[0]['subscriber_id'], $lm[0]['fb_message_id'], $lm[0]['sender']), array('PSID1', 'm_a', 'bot'));
check('one internal note on the linked conversation', array(count($ci->db->rows['internal_notes']), $ci->db->rows['internal_notes'][0]['conversation_id']), array(1, 7000));
check('note tenant = campaign owner', $ci->db->rows['internal_notes'][0]['user_id'], 5);
check('no Graph lookup when recipient_id was returned', $ci->fb_rx_login->calls, 0);
check('db_debug off during writes', in_array(TRUE, $ci->db->debug_seen, true), false);
check('db_debug restored', $ci->db->db_debug, TRUE);

cpr_buffer_capture($text_payload('111_222', 'Thanks! Here is the price.'), array('recipient_id' => 'PSID1', 'message_id' => 'm_a'));
check('retry with same message id writes nothing', cpr_flush($ci, ctx()), 0);
check('still one row, one note', array(count($ci->db->rows['livechat_messages']), count($ci->db->rows['internal_notes'])), array(1, 1));

$before = count($ci->db->debug_seen);
check('empty buffer -> 0, no queries', array(cpr_flush($ci, ctx()), count($ci->db->debug_seen)), array(0, $before));

// multi-message template: two rows, one note
$ci = new FakeCI();
cpr_buffer_capture(array('recipient' => array('comment_id' => '111_222'), 'message' => array('text' => 'one')), array('message_id' => 'm_1'));
cpr_buffer_capture(array('recipient' => array('comment_id' => '111_222'), 'message' => array('text' => 'two')), array('message_id' => 'm_2'));
check('template with two messages -> two rows via caller PSID', cpr_flush($ci, ctx(array('fallback_psid' => 'PSID2'))), 2);
check('both under the caller-resolved PSID', array_column($ci->db->rows['livechat_messages'], 'subscriber_id'), array('PSID2', 'PSID2'));
check('only one note for the comment', count($ci->db->rows['internal_notes']), 1);

// lookup fallback (IG AI / mention path)
$ci = new FakeCI();
cpr_buffer_capture(array('recipient' => array('comment_id' => '111_222'), 'message' => array('text' => 'ai')), array('message_id' => 'm_ai'));
check('no recipient_id, no caller PSID -> Graph lookup used', array(cpr_flush($ci, ctx(array('platform' => 'ig'))), $ci->fb_rx_login->calls, $ci->db->rows['livechat_messages'][0]['subscriber_id'] ?? null), array(1, 1, 'PSID_LOOKUP'));

$ci = new FakeCI(); $ci->fb_rx_login->throw = true;
cpr_buffer_capture(array('recipient' => array('comment_id' => '111_222'), 'message' => array('text' => 'ai')), array('message_id' => 'm_ai'));
check('lookup throws -> nothing written, nothing thrown', array(cpr_flush($ci, ctx()), count($ci->db->rows['livechat_messages'])), array(0, 0));

$ci = new FakeCI();
cpr_buffer_capture(array('recipient' => array('comment_id' => '111_222'), 'message' => array('text' => 'ai')), array('message_id' => 'm_ai'));
check('no token -> no lookup, skipped', array(cpr_flush($ci, ctx(array('token' => ''))), $ci->fb_rx_login->calls), array(0, 0));

// DB failure modes
$ci = new FakeCI(); $ci->db->throw_on['livechat_messages'] = true;
cpr_buffer_capture($text_payload('111_222', 'x'), array('recipient_id' => 'P', 'message_id' => 'm_x'));
check('insert throws -> 0, no exception', cpr_flush($ci, ctx()), 0);
check('db_debug restored after a throw', $ci->db->db_debug, TRUE);
check('failure logged', (bool) array_filter($GLOBALS['__log'], function ($l) { return $l[0] === 'error' && strpos($l[1], 'livechat write failed') !== false; }), true);

$ci = new FakeCI(); $ci->db->fail_insert['livechat_messages'] = true;
cpr_buffer_capture($text_payload('111_222', 'x'), array('recipient_id' => 'P', 'message_id' => 'm_y'));
check('insert returns false -> 0', cpr_flush($ci, ctx()), 0);

$ci = new FakeCI(); $ci->db->throw_on['internal_notes'] = true;
cpr_buffer_capture($text_payload('111_222', 'x'), array('recipient_id' => 'P', 'message_id' => 'm_z'));
check('note failure does not undo the message row', array(cpr_flush($ci, ctx()), count($ci->db->rows['livechat_messages'])), array(1, 1));

$ci = new FakeCI();
cpr_buffer_capture($text_payload('OTHER', 'x'), array('recipient_id' => 'P', 'message_id' => 'm_o'));
check('comment other than the reported one: row yes, note no', array(cpr_flush($ci, ctx()), count($ci->db->rows['internal_notes'])), array(1, 0));

$ci = new stdClass(); // no db at all
cpr_buffer_capture($text_payload('111_222', 'x'), array('recipient_id' => 'P', 'message_id' => 'm_q'));
set_error_handler(function ($no, $str) { throw new ErrorException($str); });
try { check('broken CI object -> 0, never throws, no warnings', cpr_flush($ci, ctx()), 0); } catch (\Throwable $e) { bad('broken CI object', $e->getMessage()); }
restore_error_handler();

echo "\n== wiring (static) ==\n";
$root = __DIR__ . '/../..';
$lib = file_get_contents($root . '/application/libraries/Fb_rx_login.php');
$ca  = file_get_contents($root . '/application/controllers/Comment_automation.php');
$ig  = file_get_contents($root . '/application/controllers/Instagram_reply.php');
check('library send_private_reply captures', preg_match('/function send_private_reply.*?cpr_buffer_capture\(\$payload, \$result\).*?return \$result;/s', $lib), 1);
check('Comment_automation overrides send_reply with capture', preg_match('/protected function send_reply\(.*?parent::send_reply.*?cpr_buffer_capture/s', $ca), 1);
check('Comment_automation flushes in both reply flows', substr_count($ca, "\$this->cpr_log_private_replies('fb'"), 2);
check('Instagram_reply overrides send_reply with capture', preg_match('/protected function send_reply\(.*?parent::send_reply.*?cpr_buffer_capture/s', $ig), 1);
check('Instagram_reply flushes in mention, delete and comment flows', substr_count($ig, '$this->cpr_log_private_replies('), 3);
check('Comment_automation flush comes after the report insert', strpos($ca, "insert_batch('facebook_ex_autoreply_report'") < strpos($ca, "\$this->cpr_log_private_replies('fb'"), true);
check('no new file under application/config', file_exists($root . '/application/config/comment_private_reply.php'), false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
