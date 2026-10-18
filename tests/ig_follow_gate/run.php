<?php
/**
 * application/helpers/ig_follow_gate_helper.php + wiring. No DB, no CI, no network.
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ig_follow_gate/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', 1);
function log_message($l, $m) {}
require __DIR__ . '/../../application/helpers/ig_follow_gate_helper.php';

$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name  -> got " . var_export($got, true) . ' want ' . var_export($want, true) . "\n"; }
}

class Q { public $r; function __construct($r) { $this->r = $r; } function row_array() { return $this->r; } }
class DB {
    public $db_debug = true; public $row = null; public $queries = array(); public $nextId = 41; public $fail = false; public $throw = false;
    function query($sql, $b = array()) {
        if ($this->throw) throw new Exception('Unknown column follow_gate');
        $this->queries[] = array($sql, $b);
        if ($this->fail) return false;
        return stripos($sql, 'SELECT') === 0 ? new Q($this->row) : true;
    }
    function insert_id() { return ++$this->nextId; }
}

// ---- settings ----
$db = new DB();
$db->row = array('id' => 9, 'page_info_table_id' => 3, 'follow_gate' => '1', 'follow_gate_text' => '', 'follow_gate_button' => 'Done', 'follow_gate_retry_text' => null);
$s = igfg_settings($db, 9);
check('settings on', is_array($s), true);
check('settings default text when empty', $s['text'], igfg_defaults()['text']);
check('settings custom button', $s['button'], 'Done');
$db->row['follow_gate'] = '0'; check('settings off => null', igfg_settings($db, 9), null);
$db->throw = true; check('column missing => null (no gate)', igfg_settings($db, 9), null);
check('db_debug restored', $db->db_debug, true);
$db->throw = false;

// ---- intercept ----
$db = new DB();
$g = new Igfg_gate($db, array('campaign_id' => 9, 'page_table_id' => 3, 'text' => 'follow first', 'button' => 'تابعت ✅ وزيادة كلام طويل جدا', 'retry' => 'r'));
$orig = json_encode(array('recipient' => array('comment_id' => 'C1'), 'message' => array('text' => 'السعر 500'), 'tag' => 'HUMAN_AGENT'), JSON_UNESCAPED_UNICODE);
$out = json_decode($g->intercept($orig), true);
check('gate replaces private reply', $out['recipient'], array('comment_id' => 'C1'));
check('gate button payload', $out['message']['attachment']['payload']['buttons'][0]['payload'], 'IGFG_42');
check('gate button clipped to 20 chars', mb_strlen($out['message']['attachment']['payload']['buttons'][0]['title'], 'UTF-8') <= 20, true);
check('gate text', $out['message']['attachment']['payload']['text'], 'follow first');
check('original parked in insert', strpos($db->queries[0][1][3], 'السعر 500') !== false, true);
$second = json_encode(array('recipient' => array('id' => 'U'), 'message' => array('text' => 'part 2')));
check('later message same request is parked (not sent)', $g->intercept($second), null);
check('parked list updated', count(json_decode(end($db->queries)[1][0], true)), 2);

$db2 = new DB(); $g2 = new Igfg_gate($db2, array('campaign_id' => 1, 'page_table_id' => 1, 'text' => 't', 'button' => 'b', 'retry' => 'r'));
$dm = json_encode(array('recipient' => array('id' => 'U'), 'message' => array('text' => 'x')));
check('non-comment message untouched', $g2->intercept($dm), $dm);
$db2->fail = true;
check('insert failure => original sent (fail open)', $g2->intercept($orig), $orig);
$db2->fail = false; $db2->throw = true;
check('insert throws => original sent (fail open)', $g2->intercept($orig), $orig);

// ---- payload / status / decide ----
check('payload ok', igfg_parse_payload('IGFG_42'), 42);
check('payload junk', igfg_parse_payload('IGFG_4x'), 0);
check('payload other', igfg_parse_payload('ECOM_ADDCART_1'), 0);
check('status follower', igfg_follow_status(array('is_user_follow_business' => true)), true);
check('status not follower', igfg_follow_status(array('is_user_follow_business' => false)), false);
check('status error => unknown', igfg_follow_status(array('error' => array('message' => 'x'))), null);
check('status field absent => unknown', igfg_follow_status(array('id' => '1')), null);
check('status null => unknown', igfg_follow_status(null), null);

$now = strtotime('2026-10-18 12:00:00');
$row = array('status' => 'pending', 'attempts' => 0, 'created_at' => '2026-10-18 11:00:00');
check('decide follower => deliver', igfg_decide($row, true, $now), 'deliver');
check('decide unknown => deliver (fail open)', igfg_decide($row, null, $now), 'deliver');
check('decide not follower => retry', igfg_decide($row, false, $now), 'retry');
check('decide waiting + follower => deliver', igfg_decide(array('status' => 'waiting') + $row, true, $now), 'deliver');
check('decide delivered => ignore', igfg_decide(array('status' => 'delivered') + $row, true, $now), 'ignore');
check('decide too many taps => ignore', igfg_decide(array('attempts' => 5) + $row, false, $now), 'ignore');
check('decide expired => ignore', igfg_decide(array('created_at' => '2026-10-01 00:00:00') + $row, true, $now), 'ignore');
check('decide missing row => ignore', igfg_decide(null, true, $now), 'ignore');

$bodies = igfg_readdress(json_encode(array(json_decode($orig, true), array('recipient' => array('id' => 'z'), 'message' => array('text' => 'p2')), 'junk')), '777');
check('readdress count (junk dropped)', count($bodies), 2);
$b0 = json_decode($bodies[0], true);
check('readdress recipient', $b0['recipient'], array('id' => '777'));
check('readdress drops tag', isset($b0['tag']), false);
check('readdress messaging_type', $b0['messaging_type'], 'RESPONSE');
check('readdress bad json', igfg_readdress('nope', '1'), array());

// ---- wiring ----
$ir = file_get_contents(__DIR__ . '/../../application/controllers/Instagram_reply.php');
$mb = file_get_contents(__DIR__ . '/../../application/controllers/Messenger_bot.php');
check('wiring: gate built on both paths', substr_count($ir, 'if($igfg_settings) $this->igfg_gate = new Igfg_gate($this->db, $igfg_settings);'), 2);
check('wiring: send_reply intercepts', strpos($ir, '$reply = $this->igfg_gate->intercept($reply);') !== false, true);
check('wiring: test mode checked before gate', strpos($ir, "if (\$this->igcg_dry_run) return array('dry_run' => true);") < strpos($ir, '$this->igfg_gate->intercept('), true);
check('wiring: postback hook', strpos($mb, "if(strpos(\$payload_id, 'IGFG_') === 0){") !== false, true);
check('wiring: hook before ecom hook', strpos($mb, "'IGFG_') === 0") < strpos($mb, "'ECOM_ADDCART_') === 0"), true);
check('wiring: one-shot delivery claim', strpos($mb, "SET status = 'delivered'") !== false && strpos($mb, "affected_rows() !== 1) return;") !== false, true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
