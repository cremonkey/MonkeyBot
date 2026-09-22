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

echo "\n== $pass passed, $fail failed ==\n";
exit($fail === 0 ? 0 : 1);
