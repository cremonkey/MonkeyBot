<?php
/**
 * AI conversation summary + Agency usage/billing readiness unit tests (master brief §13, §11).
 * No DB, no CodeIgniter bootstrap, no network — the AI call is a fake callable injected into
 * Conversation_summarizer's constructor, exactly like tests/prompt_to_flow/run.php substitutes
 * one into Flow_prompt_generator. HTTP+DB coverage (real conversation, real caching column
 * writes, tenant isolation, entitlement) is docker/scripts/staging_summary_billing_tests.sh.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/summary_billing/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('Conversation_summarizer must not need a CI instance when an ai_caller is injected'); }
function log_message($level, $msg) { /* no-op */ }

require __DIR__ . '/../../application/helpers/conversation_summary_helper.php';
require __DIR__ . '/../../application/libraries/Conversation_summarizer.php';
require __DIR__ . '/../../application/helpers/agency_billing_helper.php';

$PASS = 0; $FAIL = 0;
function ok($name) { global $PASS; $PASS++; echo "PASS  $name\n"; }
function bad($name, $why) { global $FAIL; $FAIL++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}
function contains($name, $haystack, $needle) {
    if (strpos((string) $haystack, $needle) !== false) ok($name);
    else bad($name, "\"$haystack\" does not contain \"$needle\"");
}

function ai_ok($text) { return json_encode(array('choices' => array(array('text' => $text)))); }
function ai_err($msg) { return json_encode(array('error' => array('message' => $msg))); }

$msg = function ($sender, $content, $agent_name = null) {
    return array('id' => rand(1, 999999), 'sender' => $sender, 'agent_name' => $agent_name, 'message_content' => $content, 'conversation_time' => '2026-09-22 10:00:00');
};

// ============================================================================================
echo "== conversation_summary_should_regenerate() ==\n";

check('no cached summary yet -> regenerate', conversation_summary_should_regenerate(null, '2026-09-22 10:00:00'), true);
check('no cached summary yet (empty string) -> regenerate', conversation_summary_should_regenerate('', '2026-09-22 10:00:00'), true);
check('cached summary newer than last message -> keep cache', conversation_summary_should_regenerate('2026-09-22 12:00:00', '2026-09-22 10:00:00'), false);
check('cached summary exactly at last message time -> keep cache (not strictly newer)', conversation_summary_should_regenerate('2026-09-22 10:00:00', '2026-09-22 10:00:00'), false);
check('a newer message than the cached summary -> regenerate', conversation_summary_should_regenerate('2026-09-22 10:00:00', '2026-09-22 12:00:00'), true);
check('cached summary present, no last_message_at to compare -> keep cache (nothing proves staleness)', conversation_summary_should_regenerate('2026-09-22 10:00:00', null), false);

// ============================================================================================
echo "\n== Conversation_summarizer: never invents, extracts text correctly ==\n";

$calls = 0;
$gen = new Conversation_summarizer(array('ai_caller' => function ($cfg, $messages, $overrides) use (&$calls) {
    $calls++;
    return ai_ok('Customer asked about room availability for 2 guests. Status: open, awaiting price confirmation.');
}));
$r = $gen->summarize(array('user_id' => 1), array($msg('user', 'Is the room available for 2 guests?'), $msg('bot', 'Let me check that for you.', 'AI')));
check('happy path: ok=true', $r['ok'], true);
contains('happy path: summary text passed through', $r['summary'], 'room availability');
check('happy path: exactly one AI call', $calls, 1);

$gen2 = new Conversation_summarizer(array('ai_caller' => function ($cfg, $messages, $overrides) {
    return ai_err('Anthropic API key is not configured.');
}));
$r2 = $gen2->summarize(array('user_id' => 1), array($msg('user', 'Hello')));
check('provider error: ok=false', $r2['ok'], false);
check('provider error: no fabricated summary', $r2['summary'], null);
contains('provider error: the real error message is surfaced, not swallowed', $r2['message'], 'API key is not configured');

$gen3 = new Conversation_summarizer(array('ai_caller' => function ($cfg, $messages, $overrides) { return 'not actually json'; }));
$r3 = $gen3->summarize(array('user_id' => 1), array($msg('user', 'Hello')));
check('unreadable response: ok=false', $r3['ok'], false);
check('unreadable response: no fabricated summary', $r3['summary'], null);

$gen4 = new Conversation_summarizer(array('ai_caller' => function ($cfg, $messages, $overrides) { return ai_ok('   '); }));
$r4 = $gen4->summarize(array('user_id' => 1), array($msg('user', 'Hello')));
check('blank AI text: ok=false (never an empty fabricated summary)', $r4['ok'], false);
contains('blank AI text: message explains why', $r4['message'], 'empty');

$calls5 = 0;
$gen5 = new Conversation_summarizer(array('ai_caller' => function () use (&$calls5) { $calls5++; return ai_ok('should never be seen'); }));
$r5 = $gen5->summarize(array('user_id' => 1), array());
check('no messages at all: ok=false, no AI call', $r5['ok'], false);
check('  ...the AI is never called for an empty thread', $calls5, 0);

$calls6 = 0;
$gen6 = new Conversation_summarizer(array('ai_caller' => function () use (&$calls6) { $calls6++; return ai_ok('should never be seen'); }));
$r6 = $gen6->summarize(array('user_id' => 1), array($msg('system', ''), $msg('bot', '')));
check('messages present but all blank content: ok=false, no AI call', $r6['ok'], false);
check('  ...the AI is never called when there is nothing to summarize', $calls6, 0);

// The system prompt actually sent to the model forbids inventing facts (brief §51) — pin the
// wording so a future edit cannot silently drop the guardrail.
contains('system prompt forbids inventing facts not in the transcript', conversation_summary_system_prompt(), 'Never invent');

// conversation_summary_build_messages() shape
$built = conversation_summary_build_messages(array($msg('user', 'Hi, I need a hotel room.'), $msg('bot', 'Sure, for how many guests?', 'Sales Agent')));
check('build_messages: exactly one user-role turn (Ai_provider::completion() message shape)', count($built), 1);
check('  ...role is user', $built[0]['role'], 'user');
contains('  ...customer line is labeled "Customer"', $built[0]['content'], 'Customer: Hi, I need a hotel room.');
contains('  ...bot line uses agent_name when present', $built[0]['content'], 'Sales Agent: Sure, for how many guests?');

// conversation_summary_extract_text() directly
list($t1, $e1) = conversation_summary_extract_text(ai_ok('plain text summary'));
check('extract_text: happy path', $t1, 'plain text summary');
check('  ...no error', $e1, '');
list($t2, $e2) = conversation_summary_extract_text(ai_err('boom'));
check('extract_text: error shape -> null text', $t2, null);
check('  ...error message passed through', $e2, 'boom');
list($t3, $e3) = conversation_summary_extract_text('{not json');
check('extract_text: unreadable raw -> null text', $t3, null);

// ============================================================================================
echo "\n== caching-vs-regenerate orchestration (what Inbox::summarize() does, with a fake DB row + fake AI) ==\n";

/**
 * Mirrors Inbox::summarize()'s own control flow exactly (conversation_summary_should_regenerate()
 * then, only if needed, Conversation_summarizer::summarize()) against a plain-array stand-in for
 * the `conversations` row instead of a real DB — same spirit as inbox_helper's FakeQB in
 * tests/inbox/run.php: it proves the real decision functions wired together, not a
 * reimplementation of them.
 */
function fake_summarize_flow(array &$conv_row, $refresh, $ai_caller_calls_ref, callable $ai_caller)
{
    $needs_new = $refresh || conversation_summary_should_regenerate($conv_row['last_summary_at'], $conv_row['last_message_at']);
    if (!$needs_new && !empty($conv_row['last_summary'])) {
        return array('status' => '1', 'summary' => $conv_row['last_summary'], 'cached' => true);
    }
    $summarizer = new Conversation_summarizer(array('ai_caller' => $ai_caller));
    $result = $summarizer->summarize(array('user_id' => 1), array(array('sender' => 'user', 'message_content' => 'placeholder', 'agent_name' => null)));
    if (empty($result['ok'])) return array('status' => '0', 'message' => $result['message']);
    $conv_row['last_summary'] = $result['summary'];
    $conv_row['last_summary_at'] = $conv_row['last_message_at']; // simulates "now", advanced past the triggering message
    return array('status' => '1', 'summary' => $result['summary'], 'cached' => false);
}

// Case 1: no new messages since the cached summary -> cached returned, AI NOT called.
$conv = array('last_summary' => 'Existing cached summary.', 'last_summary_at' => '2026-09-22 12:00:00', 'last_message_at' => '2026-09-22 10:00:00');
$calls_c1 = 0;
$out1 = fake_summarize_flow($conv, false, $calls_c1, function () use (&$calls_c1) { $calls_c1++; return ai_ok('FRESH — should not appear'); });
check('no new messages: cached summary is returned', $out1['summary'], 'Existing cached summary.');
check('  ...cached flag is true', $out1['cached'], true);
check('  ...the AI is NOT called', $calls_c1, 0);

// Case 2: a new message arrived since the cache -> AI IS called, cache is updated.
$conv2 = array('last_summary' => 'Stale cached summary.', 'last_summary_at' => '2026-09-22 10:00:00', 'last_message_at' => '2026-09-22 12:00:00');
$calls_c2 = 0;
$out2 = fake_summarize_flow($conv2, false, $calls_c2, function () use (&$calls_c2) { $calls_c2++; return ai_ok('Freshly regenerated summary.'); });
check('new message since cache: the AI IS called', $calls_c2, 1);
check('  ...the returned summary is the fresh one, not the stale cache', $out2['summary'], 'Freshly regenerated summary.');
check('  ...cached flag is false', $out2['cached'], false);
check('  ...conversations.last_summary would be updated', $conv2['last_summary'], 'Freshly regenerated summary.');

// Case 3: explicit refresh=1 bypasses the cache even with no new messages.
$conv3 = array('last_summary' => 'Cached, still fresh by timestamps.', 'last_summary_at' => '2026-09-22 12:00:00', 'last_message_at' => '2026-09-22 10:00:00');
$calls_c3 = 0;
$out3 = fake_summarize_flow($conv3, true, $calls_c3, function () use (&$calls_c3) { $calls_c3++; return ai_ok('Regenerated on explicit request.'); });
check('explicit refresh: the AI IS called even though the cache looked fresh', $calls_c3, 1);
check('  ...summary reflects the regeneration', $out3['summary'], 'Regenerated on explicit request.');

// ============================================================================================
echo "\n== Agency usage/billing readiness math (application/helpers/agency_billing_helper.php) ==\n";

$now = strtotime('2026-09-22 09:00:00');

check('expiry 30 days out -> +30', agency_billing_days_until_expiry(date('Y-m-d H:i:s', $now + 30 * 86400), $now), 30);
check('expiry at the exact same instant as now -> 0', agency_billing_days_until_expiry(date('Y-m-d H:i:s', $now), $now), 0);
check('expiry 10 days ago -> -10', agency_billing_days_until_expiry(date('Y-m-d H:i:s', $now - 10 * 86400), $now), -10);
check('no expiry date (empty) -> null', agency_billing_days_until_expiry('', $now), null);
check('no expiry date (zero date) -> null', agency_billing_days_until_expiry('0000-00-00', $now), null);

$s_far = agency_billing_expiry_status(30);
check('30 days out: status ok', $s_far['status'], 'ok');
check('  ...no red flag', $s_far['flag'], null);

$s_soon = agency_billing_expiry_status(3);
check('3 days out: status due_soon', $s_soon['status'], 'due_soon');
check('  ...flagged red (<7 days, brief §11)', $s_soon['flag'], 'danger');

$s_boundary = agency_billing_expiry_status(7);
check('exactly 7 days out: NOT due_soon (brief says "<7", 7 itself is still ok)', $s_boundary['status'], 'ok');

$s_expired = agency_billing_expiry_status(-2);
check('already expired: status expired', $s_expired['status'], 'expired');
check('  ...flagged red', $s_expired['flag'], 'danger');
contains('  ...label says how long ago', $s_expired['label'], '2d ago');

$s_none = agency_billing_expiry_status(null);
check('no expiry to judge (e.g. Admin/agency account): status none, no flag', $s_none['status'], 'none');
check('  ...no flag', $s_none['flag'], null);

check('renewal due soon: true just under threshold', agency_billing_is_renewal_due_soon(6), true);
check('renewal due soon: false at threshold', agency_billing_is_renewal_due_soon(7), false);
check('renewal due soon: true when already expired (negative days)', agency_billing_is_renewal_due_soon(-1), true);
check('renewal due soon: false with no expiry to compare', agency_billing_is_renewal_due_soon(null), false);

check('module extra_text "" (e.g. 340 Bot - AI Reply) -> cumulative, not monthly', agency_billing_is_module_monthly(''), false);
check('module extra_text "month" -> monthly', agency_billing_is_module_monthly('month'), true);

$u1 = agency_billing_usage_percent('100', 25);
check('usage 25/100 -> 25%', $u1['percent'], 25);
check('  ...not unlimited', $u1['unlimited'], false);

$u2 = agency_billing_usage_percent('0', 500);
check('limit 0 -> unlimited (brief/Home::_check_usage semantics: 0 means unlimited)', $u2['unlimited'], true);
check('  ...percent is null, never a divide-by-zero', $u2['percent'], null);

$u3 = agency_billing_usage_percent(null, 10);
check('missing limit key -> unlimited (never blocks, matches Home::_check_usage)', $u3['unlimited'], true);

$u4 = agency_billing_usage_percent('10', 55);
check('usage far over limit is capped at 100%, never > 100', $u4['percent'], 100);

$u5 = agency_billing_usage_percent('3', 1);
check('rounding: 1/3 -> 33%', $u5['percent'], 33);

check('decode_monthly_limit: normal JSON', agency_billing_decode_monthly_limit('{"340":"100","66":"0"}'), array('340' => '100', '66' => '0'));
check('decode_monthly_limit: empty string -> empty array, never a crash', agency_billing_decode_monthly_limit(''), array());
check('decode_monthly_limit: malformed JSON -> empty array, never a crash', agency_billing_decode_monthly_limit('{not json'), array());
check('decode_monthly_limit: null -> empty array', agency_billing_decode_monthly_limit(null), array());

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL === 0 ? 0 : 1);
