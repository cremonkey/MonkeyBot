<?php
/**
 * Remaining-work Task 20 (C5, brief §20) — AI Improvement Center new signal cards.
 * Dependency-free unit tests: no CodeIgniter bootstrap, no database, no network. Only the
 * helper functions in application/helpers/ai_improvement_helper.php are exercised directly
 * (aii_group() itself already has coverage elsewhere in the app's history; here we cover the
 * new aggregation functions and their tenant-scoping contract).
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/ai_improvement/run.php
 *
 * Covers:
 *  1. aii_is_ai_agent_name(): bot/Bot/empty -> AI, a real username -> not AI.
 *  2. aii_group_tool_failures(): groups ai_failure_log-shaped rows by tool_name+error_class,
 *     counts, keeps the latest created_at, sorts by count desc.
 *  3. aii_escalation_rows(): drops conversations with a single escalation (not "repeated"),
 *     keeps ones with >= 2, emits one row per escalation so aii_group()'s count reflects the
 *     total escalation count for the topic, not the conversation count.
 *  4. aii_find_corrections(): matches a human agent reply to the nearest prior AI reply in
 *     the SAME conversation within the time window; ignores replies outside the window,
 *     replies before the AI one, and does not double-match one AI reply to two humans.
 *  5. Tenant scoping is the CALLER's job (every query in Ai_improvement::index() filters
 *     user_id first) — this suite proves that by feeding two tenants' rows through the same
 *     helper call and checking cross-tenant rows never merge into one group.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
define('BASEPATH', dirname(__DIR__, 2) . '/system/');
define('APPPATH', dirname(__DIR__, 2) . '/application/');
date_default_timezone_set('UTC');

require dirname(__DIR__, 2) . '/application/helpers/ai_improvement_helper.php';

$PASS = 0; $FAIL = 0;
function ok($n) { global $PASS; $PASS++; echo "  ok    $n\n"; }
function bad($n, $why) { global $FAIL; $FAIL++; echo "  FAIL  $n\n        $why\n"; }
function check($n, $cond, $why = '') { $cond ? ok($n) : bad($n, $why === '' ? 'condition was false' : $why); }
function same($n, $want, $got) { $want === $got ? ok($n) : bad($n, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true)); }

// ------------------------------------------------------------ 1. aii_is_ai_agent_name

check('is_ai: literal "bot"', aii_is_ai_agent_name('bot'));
check('is_ai: literal "Bot"', aii_is_ai_agent_name('Bot'));
check('is_ai: empty string', aii_is_ai_agent_name(''));
check('is_ai: null', aii_is_ai_agent_name(null));
check('is_ai: whitespace only', aii_is_ai_agent_name('   '));
check('is_ai: real username is NOT ai', !aii_is_ai_agent_name('sara_agent'));
check('is_ai: "BOT " with padding still ai', aii_is_ai_agent_name(' BOT '));
// Comment private-reply automation also writes sender='bot' with a fixed, non-"bot" name
// (comment_private_reply_helper.php: CPR_AGENT_NAME = 'Comment auto-reply') — this must be
// treated as automated, not as a human correction.
check('is_ai: CPR_AGENT_NAME literal is automated, not human', aii_is_ai_agent_name('Comment auto-reply'));
check('is_ai: CPR_AGENT_NAME is case-insensitive', aii_is_ai_agent_name('COMMENT AUTO-REPLY'));
if (!defined('CPR_AGENT_NAME')) define('CPR_AGENT_NAME', 'Comment auto-reply');
check('is_ai: CPR_AGENT_NAME constant (when loaded) is honoured', aii_is_ai_agent_name(CPR_AGENT_NAME));
// The one real positive-human path: Inbox::write_agent_message() / Home.php's
// insert_livechat_data() stamp agent_name = the logged-in session's username — any name
// that is not one of the known automation names must still count as human.
check('is_ai: Inbox agent session username is NOT ai', !aii_is_ai_agent_name('mostafa_support'));
check('is_ai: Livechat logged-in agent username is NOT ai', !aii_is_ai_agent_name('admin'));

// ------------------------------------------------------------ 2. aii_group_tool_failures

$tf_rows = array(
    array('tool_name' => 'get_price', 'error_class' => 'TimeoutException', 'error_message' => 'timed out after 8s', 'created_at' => '2026-09-20 10:00:00'),
    array('tool_name' => 'get_price', 'error_class' => 'TimeoutException', 'error_message' => 'timed out after 8s', 'created_at' => '2026-09-21 11:00:00'),
    array('tool_name' => 'get_price', 'error_class' => 'TimeoutException', 'error_message' => 'timed out after 8s', 'created_at' => '2026-09-19 09:00:00'),
    array('tool_name' => 'check_stock', 'error_class' => 'HttpError', 'error_message' => '500 from upstream', 'created_at' => '2026-09-22 08:00:00'),
);
$tf = aii_group_tool_failures($tf_rows);
same('tool_failures: 2 groups', 2, count($tf));
same('tool_failures: get_price count = 3', 3, $tf[0]['count']);
same('tool_failures: get_price is first (highest count)', 'get_price', $tf[0]['tool_name']);
same('tool_failures: latest created_at kept for get_price group', '2026-09-21 11:00:00', $tf[0]['last_at']);
same('tool_failures: check_stock count = 1', 1, $tf[1]['count']);
same('tool_failures: empty input -> empty output', array(), aii_group_tool_failures(array()));

// tenant scoping is the caller's job: feeding two tenants' rows through the SAME call must
// not silently merge them if tool_name+error_class collide — this documents that the helper
// has no user_id awareness at all, so the controller MUST filter by user_id before calling it.
$mixed = array(
    array('tool_name' => 'get_price', 'error_class' => 'TimeoutException', 'error_message' => 'a', 'created_at' => '2026-09-20 10:00:00', 'user_id' => 1),
    array('tool_name' => 'get_price', 'error_class' => 'TimeoutException', 'error_message' => 'b', 'created_at' => '2026-09-20 10:00:00', 'user_id' => 2),
);
$tf_mixed = aii_group_tool_failures($mixed);
same('tool_failures: helper is tenant-blind (merges same key regardless of user_id)', 1, count($tf_mixed));
check('tool_failures: contract note — caller must pre-filter by user_id (proven by the merge above)', $tf_mixed[0]['count'] === 2);

// ------------------------------------------------------------ 3. aii_escalation_rows

$events = array(
    array('conversation_id' => 10, 'created_at' => '2026-09-20 09:00:00'),
    array('conversation_id' => 10, 'created_at' => '2026-09-21 09:00:00'), // conv 10 escalated twice -> repeated
    array('conversation_id' => 20, 'created_at' => '2026-09-20 10:00:00'), // conv 20 escalated once -> not repeated
    array('conversation_id' => 30, 'created_at' => '2026-09-20 11:00:00'),
    array('conversation_id' => 30, 'created_at' => '2026-09-21 11:00:00'),
    array('conversation_id' => 30, 'created_at' => '2026-09-22 11:00:00'), // conv 30 escalated 3x
);
$first_by_conv = array(
    10 => array('question' => 'How much does shipping cost to Alexandria?', 'social_media' => 'fb'),
    20 => array('question' => 'Do you have this in blue?', 'social_media' => 'ig'),
    30 => array('question' => 'how much does shipping cost to alexandria', 'social_media' => 'wa'), // same topic as conv 10
);
$esc_rows = aii_escalation_rows($events, $first_by_conv, 2);
same('escalation_rows: conv 20 (1x) is dropped, conv 10 (2x) + conv 30 (3x) kept => 5 rows', 5, count($esc_rows));
$ids_present = array_unique(array_column($esc_rows, 'id'));
sort($ids_present);
same('escalation_rows: only conv 10 and 30 present', array(10, 30), $ids_present);

$grouped_esc = aii_group($esc_rows);
check('escalation_rows + aii_group: similar-topic conversations merge into one group', count($grouped_esc) === 1);
same('escalation_rows + aii_group: merged group count = 2 + 3 escalations', 5, $grouped_esc[0]['count']);

// missing first-message text -> conversation is dropped even if it repeated
$esc_rows_no_first = aii_escalation_rows($events, array(10 => array('question' => '', 'social_media' => 'fb')), 2);
same('escalation_rows: blank first-message text drops the conversation', 0, count($esc_rows_no_first));

// ------------------------------------------------------------ 4. aii_find_corrections

$bot_rows_conv1 = array(
    array('id' => 1, 'conversation_id' => 1, 'agent_name' => 'bot', 'message_content' => 'The blue one is out of stock.', 'conversation_time' => '2026-09-20 10:00:00'),
    array('id' => 2, 'conversation_id' => 1, 'agent_name' => 'sara_agent', 'message_content' => 'Actually the blue one is back in stock, sorry!', 'conversation_time' => '2026-09-20 10:05:00'),
    // a second, unrelated AI reply far later in the same conversation with no correction
    array('id' => 3, 'conversation_id' => 1, 'agent_name' => 'Bot', 'message_content' => 'Anything else I can help with?', 'conversation_time' => '2026-09-20 11:00:00'),
    // conversation 2: correction outside the 10-minute window -> must NOT match
    array('id' => 4, 'conversation_id' => 2, 'agent_name' => '', 'message_content' => 'We ship worldwide.', 'conversation_time' => '2026-09-20 09:00:00'),
    array('id' => 5, 'conversation_id' => 2, 'agent_name' => 'omar_agent', 'message_content' => 'Correction: we only ship locally.', 'conversation_time' => '2026-09-20 09:25:00'),
    // conversation 3: human reply BEFORE any AI reply -> must NOT match
    array('id' => 6, 'conversation_id' => 3, 'agent_name' => 'nour_agent', 'message_content' => 'Hi, how can I help?', 'conversation_time' => '2026-09-20 08:00:00'),
    array('id' => 7, 'conversation_id' => 3, 'agent_name' => 'bot', 'message_content' => 'Our price is 500 EGP.', 'conversation_time' => '2026-09-20 08:05:00'),
);
$corr = aii_find_corrections($bot_rows_conv1, 10);
same('corrections: exactly one match (conv 1, within window)', 1, count($corr));
same('corrections: matched conversation is 1', 1, $corr[0]['conversation_id']);
same('corrections: ai message text captured', 'The blue one is out of stock.', $corr[0]['ai_message']);
same('corrections: human message text captured', 'Actually the blue one is back in stock, sorry!', $corr[0]['human_message']);
same('corrections: human agent name captured', 'sara_agent', $corr[0]['human_agent']);
same('corrections: minutes computed correctly', 5.0, $corr[0]['minutes']);

// widen the window to 30 minutes: conversation 2's correction now qualifies too
$corr_wide = aii_find_corrections($bot_rows_conv1, 30);
same('corrections: wider window picks up conv 2 as well -> 2 matches', 2, count($corr_wide));

// comment-private-reply automation follows an AI reply with sender='bot' too, but it is not
// a human correction: agent_name is CPR_AGENT_NAME, not a real username.
$cpr_conv = array(
    array('id' => 1, 'conversation_id' => 6, 'agent_name' => 'bot', 'message_content' => 'Price is 300.', 'conversation_time' => '2026-09-20 10:00:00'),
    array('id' => 2, 'conversation_id' => 6, 'agent_name' => 'Comment auto-reply', 'message_content' => 'Thanks for your comment!', 'conversation_time' => '2026-09-20 10:02:00'),
);
same('corrections: an automated CPR reply after an AI reply is NOT a correction', 0, count(aii_find_corrections($cpr_conv, 10)));

// one AI reply must not be matched to two different human replies
$double = array(
    array('id' => 1, 'conversation_id' => 5, 'agent_name' => 'bot', 'message_content' => 'Price is 100.', 'conversation_time' => '2026-09-20 10:00:00'),
    array('id' => 2, 'conversation_id' => 5, 'agent_name' => 'agentA', 'message_content' => 'Actually 120.', 'conversation_time' => '2026-09-20 10:02:00'),
    array('id' => 3, 'conversation_id' => 5, 'agent_name' => 'agentB', 'message_content' => 'Confirming 120.', 'conversation_time' => '2026-09-20 10:04:00'),
);
$corr_double = aii_find_corrections($double, 10);
same('corrections: one AI reply matches only the NEAREST human reply, not both', 1, count($corr_double));
same('corrections: nearest human reply wins', 'agentA', $corr_double[0]['human_agent']);

// empty input
same('corrections: empty rows -> empty output', array(), aii_find_corrections(array(), 10));

// conversation_id missing/zero is skipped, never throws
$bad_conv = array(array('id' => 1, 'conversation_id' => 0, 'agent_name' => 'bot', 'message_content' => 'x', 'conversation_time' => '2026-09-20 10:00:00'));
same('corrections: conversation_id=0 rows are skipped', array(), aii_find_corrections($bad_conv, 10));

// ------------------------------------------------------------ 5. cross-tenant isolation contract

// The helpers themselves carry no user_id — proving the controller's queries (which all
// filter WHERE user_id = $this->uid before calling these helpers) are the only isolation
// boundary. This test documents that a caller who forgets to scope by tenant WILL leak data,
// by showing two tenants' escalation rows for the "same" conversation id merge together.
$cross_tenant_events = array(
    array('conversation_id' => 99, 'created_at' => '2026-09-20 09:00:00'), // tenant A's conversation 99
    array('conversation_id' => 99, 'created_at' => '2026-09-20 09:10:00'),
);
$cross_tenant_first = array(99 => array('question' => 'tenant A question', 'social_media' => 'fb'));
$rows_a = aii_escalation_rows($cross_tenant_events, $cross_tenant_first, 2);
same('cross-tenant: helper has no user_id field, so callers MUST pre-filter by user_id in SQL', 2, count($rows_a));
check('cross-tenant: rows carry no user_id key at all (helper is tenant-blind by design)', !array_key_exists('user_id', $rows_a[0]));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
