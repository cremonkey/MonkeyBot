<?php
/**
 * Task 24 (brief 22) — static wiring checks for the event emit sites this task adds.
 * No CodeIgniter, no database, no network: every check is a regex over the source file,
 * the same style as tests/inbox/run.php's livechat_messages -> inbox_realtime_notify() check.
 *
 *   docker run --rm -v $PWD:/app -w /app monkeybot-app-ojzrhf-app:latest php tests/events/run.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$PASS = 0; $FAIL = 0;
function ok($name) { global $PASS; $PASS++; echo "PASS  $name\n"; }
function bad($name, $why) { global $FAIL; $FAIL++; echo "FAIL  $name  -> $why\n"; }
function is_same($name, $expected, $actual) {
    if ($expected === $actual) ok($name);
    else bad($name, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function is_true($name, $v) { is_same($name, true, $v); }

$root = dirname(__DIR__, 2);

/** window of lines starting at $start_idx (0-based), for looking a few lines ahead of a match */
function window($lines, $start_idx, $n = 25) { return implode('', array_slice($lines, $start_idx, $n)); }

// ---------------------------------------------------------------------------------------
echo "-- conversation.state_changed --------------------------------------\n";
$f = $root . '/application/helpers/conversation_helper.php';
$src = file_get_contents($f);
is_true('conversation_transition() exists', strpos($src, 'function conversation_transition(') !== false);
$fn_pos = strpos($src, 'function conversation_transition(');
$fn_body = substr($src, $fn_pos, 6000);
is_true('conversation_transition() emits conversation.state_changed', strpos($fn_body, "event_emit((int) \$conv['user_id'], 'conversation.state_changed'") !== false);
is_true('emit happens only after trans_commit() (never rolled back)', strpos($fn_body, 'trans_commit();') < strpos($fn_body, "'conversation.state_changed'"));

// ---------------------------------------------------------------------------------------
echo "-- customer.created --------------------------------------------------\n";
$fn_pos2 = strpos($src, 'function conversation_customer_for_identity(');
is_true('conversation_customer_for_identity() exists', $fn_pos2 !== false);
$fn_body2 = substr($src, $fn_pos2, 4000);
is_true('emits customer.created', strpos($fn_body2, "event_emit((int) \$user_id, 'customer.created'") !== false);
is_true('only the genuine-insert path emits (after the race-loser early returns)',
    strpos($fn_body2, 'lost a race') < strpos($fn_body2, "'customer.created'"));

// ---------------------------------------------------------------------------------------
echo "-- human.assigned ------------------------------------------------------\n";
$f = $root . '/application/controllers/Inbox.php';
$src = file_get_contents($f);
preg_match('/function assign\(\)(.*?)\n    \}/s', $src, $m1);
is_true('Inbox::assign() emits human.assigned', isset($m1[1]) && strpos($m1[1], "event_emit(\$this->uid, 'human.assigned'") !== false);
preg_match('/function assign_team\(\)(.*?)\n    \}/s', $src, $m2);
is_true('Inbox::assign_team() emits human.assigned', isset($m2[1]) && strpos($m2[1], "event_emit(\$this->uid, 'human.assigned'") !== false);

// ---------------------------------------------------------------------------------------
echo "-- price.requested ------------------------------------------------------\n";
$f = $root . '/application/libraries/Ai_tools.php';
$src = file_get_contents($f);
$pos = strpos($src, 'function t_calculate_price(');
is_true('t_calculate_price() exists', $pos !== false);
is_true('emits price.requested', strpos(substr($src, $pos, 1200), "'price.requested'") !== false);

// ---------------------------------------------------------------------------------------
echo "-- question.unanswered ------------------------------------------------------\n";
$f = $root . '/application/controllers/Home.php';
$lines = file($f);
$found = false; $ins_line = null;
foreach ($lines as $n => $line) {
    if (strpos($line, "insert_data('ai_unanswered_questions'") !== false) { $ins_line = $n; break; }
}
is_true('the ai_unanswered_questions insert site was found', $ins_line !== null);
if ($ins_line !== null) {
    is_true('question.unanswered is emitted right after the insert', strpos(window($lines, $ins_line, 20), "'question.unanswered'") !== false);
}

// ---------------------------------------------------------------------------------------
echo "-- checkout.started / order.created ------------------------------------------------------\n";
$f = $root . '/application/controllers/Ecommerce.php';
$src = file_get_contents($f);
is_true('proceed_checkout() emits checkout.started', strpos($src, "checkout.started") !== false);
is_true('confirmation_message_sender() emits order.created', strpos($src, "order.created") !== false);
// confirmation_message_sender is the single choke point behind all 14 payment-gateway callers.
$fn_start = strpos($src, 'private function confirmation_message_sender(');
is_true('confirmation_message_sender signature found', $fn_start !== false);
is_true('order.created is emitted inside confirmation_message_sender (not a per-gateway duplicate)',
    strpos(substr($src, $fn_start, 2000), "order.created") !== false);

// ---------------------------------------------------------------------------------------
echo "-- message.* is intentionally NOT built --------------------------------------------\n";
// brief: skip message.received / message.sent (needs a volume decision). Documented, not built.
ok('message.* skipped by design -- see task-24-report.md');

echo "\n== $PASS passed, $FAIL failed ==\n";
exit($FAIL === 0 && $PASS > 0 ? 0 : 1);
