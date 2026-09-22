<?php
// Channel Policy Engine unit tests. Run: php tests/policy/run.php  (no DB, no CI bootstrap)
define('BASEPATH', 1);
function get_instance() { throw new Exception('not needed'); }
require __DIR__ . '/../../application/helpers/reengage_helper.php';
require __DIR__ . '/../../application/helpers/channel_policy_helper.php';
$now = strtotime('2026-09-23 12:00:00'); $pass = 0; $fail = 0;
$t = function ($name, $platform, $ctx, $allowed, $reason, $tag = null) use (&$pass, &$fail, $now) {
    $ctx['now'] = $now; $d = channel_policy_decide($platform, $ctx);
    $ok = $d['allowed'] === $allowed && $d['reason'] === $reason && $d['tag'] === $tag;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok ? '' : '  -> ' . json_encode($d)) . "\n";
};
$h = function ($hours) use ($now) { return date('Y-m-d H:i:s', $now - $hours * 3600); };
$t('fb 2h ago, automation', 'fb', array('sender' => 'automation', 'last_inbound_at' => $h(2)), true, 'in_24h_window');
$t('fb 23h ago, human', 'fb', array('sender' => 'human', 'last_inbound_at' => $h(23)), true, 'in_24h_window');
$t('fb 30h ago, automation denied', 'fb', array('sender' => 'automation', 'last_inbound_at' => $h(30)), false, 'automation_outside_24h_window');
$t('fb 30h ago, human with HUMAN_AGENT', 'fb', array('sender' => 'human', 'last_inbound_at' => $h(30)), true, 'human_agent_tag_within_7_days', 'HUMAN_AGENT');
$t('ig 6 days, human with HUMAN_AGENT', 'ig', array('sender' => 'human', 'last_inbound_at' => $h(144)), true, 'human_agent_tag_within_7_days', 'HUMAN_AGENT');
$t('ig 8 days, human denied', 'ig', array('sender' => 'human', 'last_inbound_at' => $h(192)), false, 'outside_7_day_window');
$t('fb unknown last inbound -> denied (safe default)', 'fb', array('sender' => 'human', 'last_inbound_at' => null), false, 'outside_7_day_window');
$t('fb garbage timestamp -> denied', 'fb', array('sender' => 'human', 'last_inbound_at' => 'not-a-date'), false, 'outside_7_day_window');
$t('fb opted out, automation denied', 'fb', array('sender' => 'automation', 'last_inbound_at' => $h(1), 'opted_out' => true), false, 'opted_out');
$t('fb opted out, human in window still allowed', 'fb', array('sender' => 'human', 'last_inbound_at' => $h(1), 'opted_out' => true), true, 'in_24h_window');
$t('wa_cloud 2h free text', 'wa_cloud', array('sender' => 'automation', 'last_inbound_at' => $h(2)), true, 'in_24h_customer_service_window');
$t('wa_cloud 30h needs template (even human)', 'wa_cloud', array('sender' => 'human', 'last_inbound_at' => $h(30)), false, 'template_required_outside_24h');
$t('openwa automation to never-contacted number denied', 'wa', array('sender' => 'automation', 'last_inbound_at' => null), false, 'never_contacted_us');
$t('openwa human to known contact allowed', 'wa', array('sender' => 'human', 'last_inbound_at' => $h(500)), true, 'session_channel');
$t('telegram without started chat denied', 'tg', array('sender' => 'human'), false, 'user_never_started_bot');
$t('telegram started chat allowed', 'tg', array('sender' => 'automation', 'last_inbound_at' => $h(900)), true, 'chat_started');
$t('web without session denied', 'web', array('sender' => 'human'), false, 'no_widget_session');
$t('tiktok unsupported', 'tiktok', array('sender' => 'human', 'last_inbound_at' => $h(1)), false, 'channel_send_unsupported');
$t('unknown channel denied', 'sms', array('sender' => 'human', 'last_inbound_at' => $h(1)), false, 'unknown_channel');
$t('explain covers every reason', 'fb', array('sender' => 'human', 'last_inbound_at' => $h(1)), true, 'in_24h_window');
foreach (array('in_24h_window','human_agent_tag_within_7_days','automation_outside_24h_window','outside_7_day_window','in_24h_customer_service_window','template_required_outside_24h','never_contacted_us','user_never_started_bot','no_widget_session','channel_send_unsupported','opted_out') as $r)
    if (channel_policy_explain($r) === $r) { $fail++; echo "FAIL  no explanation for $r\n"; }
echo "== $pass passed, $fail failed ==\n"; exit($fail ? 1 : 0);
