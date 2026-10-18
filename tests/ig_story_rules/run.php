<?php
/**
 * application/helpers/ig_story_rules_helper.php + wiring. No DB, no CI, no network.
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ig_story_rules/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', 1);
function log_message($l, $m) {}
require __DIR__ . '/../../application/helpers/ig_story_rules_helper.php';

$pass = 0; $fail = 0;
function check($name, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "PASS  $name\n"; }
    else { $fail++; echo "FAIL  $name  -> got " . var_export($got, true) . ' want ' . var_export($want, true) . "\n"; }
}
function R($id, $story, $kw, $active = 1) { return array('id' => $id, 'story_id' => $story, 'keywords' => $kw, 'is_active' => $active, 'reply_text' => "r$id", 'button_title' => '', 'button_url' => ''); }
$id = function ($r) { return $r === null ? null : $r['id']; };

$rules = array(R(1, null, 'السعر, price'), R(2, 'S1', ''), R(3, 'S2', 'link'), R(4, null, 'link', 0));
check('story rule beats keyword rule', $id(igsr_pick_rule($rules, 'S1', 'السعر؟')), 2);
check('keyword rule for other story', $id(igsr_pick_rule($rules, 'S9', 'كام السعر')), 1);
check('arabic normalization in keywords', $id(igsr_pick_rule($rules, 'S9', 'السِّعر')), 1);
check('no match => null (generic bot)', $id(igsr_pick_rule($rules, 'S9', 'جميل')), null);
check('story rule with keyword needs keyword', $id(igsr_pick_rule($rules, 'S2', 'wow')), null);
check('story rule with keyword fires', $id(igsr_pick_rule($rules, 'S2', 'send link')), 3);
check('paused rule ignored', $id(igsr_pick_rule(array(R(4, null, 'link', 0)), 'S9', 'link')), null);
check('keyword not inside longer word', $id(igsr_pick_rule($rules, 'S9', 'priceless')), null);
check('empty text, story rule without keywords', $id(igsr_pick_rule($rules, 'S1', '')), 2);

$m = json_decode(igsr_message(R(1, null, 'x'), '55'), true);
check('text reply', $m['message'], array('text' => 'r1'));
check('recipient + RESPONSE', array($m['recipient']['id'], $m['messaging_type']), array('55', 'RESPONSE'));
$r = R(1, null, 'x'); $r['button_title'] = 'احجز الآن'; $r['button_url'] = 'https://x.co/a';
$m = json_decode(igsr_message($r, '55'), true);
check('button reply', $m['message']['attachment']['payload']['buttons'][0], array('type' => 'web_url', 'url' => 'https://x.co/a', 'title' => 'احجز الآن'));
$r['button_url'] = 'javascript:alert(1)';
check('non-https link dropped', isset(json_decode(igsr_message($r, '1'), true)['message']['text']), true);

$v = function ($a) { return igsr_validate($a + array('reply_text' => 'hi', 'story_id' => '', 'keywords' => 'k', 'button_url' => '', 'button_title' => '')); };
check('valid', $v(array()), '');
check('needs reply', $v(array('reply_text' => ' ')), 'Reply text is required');
check('needs story or keyword', $v(array('keywords' => '')), 'Pick a story or enter at least one keyword');
check('button needs both', $v(array('button_title' => 'Go')), 'Button needs both a title and a link');
check('button https only', $v(array('button_title' => 'Go', 'button_url' => 'http://x')), 'Button link must start with https://');

class Q { public $r; function __construct($r) { $this->r = $r; } function result_array() { return $this->r; } }
class DB { public $db_debug = true; public $rules = array(); public $q = array(); public $throw = false;
    function query($sql, $b = array()) { if ($this->throw) throw new Exception('no table'); $this->q[] = $sql; return stripos($sql, 'SELECT') === 0 ? new Q($this->rules) : true; } }
$db = new DB(); $db->rules = $rules;
$body = json_decode(igsr_handle($db, 3, 'S1', 'https://cdn/x', 'hi', '77'), true);
check('handle: picked + addressed', array($body['message']['text'], $body['recipient']['id']), array('r2', '77'));
check('handle: story recorded first', stripos($db->q[0], 'INSERT INTO ig_story_seen') === 0, true);
check('handle: sent_count bumped', stripos(end($db->q), 'UPDATE ig_story_rules') === 0, true);
check('handle: no match => null', igsr_handle($db, 3, 'S9', '', 'nothing', '77'), null);
$db->throw = true;
check('handle: tables missing => null (fallthrough)', igsr_handle($db, 3, 'S1', '', 'hi', '77'), null);
check('handle: db_debug restored', $db->db_debug, true);

$mb = file_get_contents(__DIR__ . '/../../application/controllers/Messenger_bot.php');
$a = strpos($mb, '$igsr_body = igsr_handle('); $b = strpos($mb, "'messenger_bot.keyword_type' => 'story-private-reply'");
check('wiring: story rules run before generic story bot', $a !== false && $b !== false && $a < $b, true);
$ir = file_get_contents(__DIR__ . '/../../application/controllers/Instagram_reply.php');
check('wiring: endpoints scoped to owner', substr_count($ir, "'user_id'=>\$this->user_id))->") >= 3, true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
