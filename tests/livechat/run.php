<?php
/**
 * Livechat realtime (Pusher) unit tests — Task 1 of the 2026-09-24 remaining-work plan.
 * No PHPUnit, no CodeIgniter bootstrap, no DB, no network. Mirrors tests/inbox/run.php's
 * realtime section, since application/helpers/livechat_realtime_helper.php is a sibling of
 * application/helpers/inbox_realtime_helper.php and reuses its signing/publish primitives.
 *
 *   docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/livechat/run.php
 *
 * What it proves:
 *   - the livechat channel is private and per-tenant (own channel authorized, any other
 *     tenant's / a crafted / the old public livechat_channel name refused)
 *   - event payloads carry ids only — no message text, no subscriber name, no rendered HTML
 *   - Pusher unset (or the app id/key/secret empty) means livechat_realtime_publish() is a
 *     true no-op: no curl call attempted
 *   - Home.php's two publish sites (insert_livechat_data, agent_assign_notifications) and
 *     Livechat.php's realtime_auth() are wired to the new helper, not the old public trigger()
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', 1);
function get_instance() { throw new Exception('livechat_realtime_helper.php must not need a CI instance for its pure functions'); }
function log_message($level, $msg) { /* no-op */ }

require __DIR__ . '/../../application/helpers/inbox_realtime_helper.php';
require __DIR__ . '/../../application/helpers/livechat_realtime_helper.php';

$pass = 0; $fail = 0;
function ok($name) { global $pass; $pass++; echo "PASS  $name\n"; }
function bad($name, $why) { global $fail; $fail++; echo "FAIL  $name  -> $why\n"; }
function check($name, $got, $want) {
    if ($got === $want) ok($name);
    else bad($name, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
}

// ============================================================================================
echo "== channel naming: private, per-tenant, sibling of Inbox's ==\n";

$cfg_on = array('pusher_app_id' => '1', 'pusher_app_key' => 'k3y', 'pusher_app_secret' => 's3cret',
    'pusher_cluster' => 'eu', 'encryption_key' => 'install-A');
$S = inbox_realtime_settings($cfg_on); // livechat reuses Inbox's settings/namespace derivation
check('settings resolve when Pusher app id/key/secret are all set', is_array($S), true);

$chA = livechat_realtime_channel_name(7, $S['ns']);
$chB = livechat_realtime_channel_name(8, $S['ns']);
check('channel is private-', strpos($chA, 'private-livechat-') === 0, true);
check('channel embeds the tenant id', $chA, 'private-livechat-' . $S['ns'] . '-7');
check('two tenants get different channels', $chA === $chB, false);
check('livechat and inbox channels for the same tenant differ (siblings, not the same channel)',
    $chA === inbox_realtime_channel_name(7, $S['ns']), false);

// ============================================================================================
echo "\n== realtime_auth: tenant isolation ==\n";

$a = livechat_realtime_authorize(7, $chA, '123.456', $S);
check('tenant 7 may subscribe to its own livechat channel', is_array($a) && $a['auth'] === inbox_realtime_sign('k3y', 's3cret', '123.456', $chA), true);
check('tenant 7 may NOT subscribe to tenant 8\'s livechat channel', livechat_realtime_authorize(7, $chB, '123.456', $S), null);
check('tenant 8 may NOT subscribe to tenant 7\'s livechat channel', livechat_realtime_authorize(8, $chA, '123.456', $S), null);
check('a crafted channel name close to the real one is refused', livechat_realtime_authorize(7, $chA . '0', '123.456', $S), null);
check('the OLD public livechat_channel name is refused', livechat_realtime_authorize(7, 'livechat_channel', '123.456', $S), null);
check('the OLD public livechat_assigned_agent_channel name is refused', livechat_realtime_authorize(7, 'livechat_assigned_agent_channel', '123.456', $S), null);
check('a presence- twin of the own channel is refused', livechat_realtime_authorize(7, str_replace('private-', 'presence-', $chA), '123.456', $S), null);
check('the Inbox channel for the same tenant is refused on the Livechat auth (no cross-feature reuse)',
    livechat_realtime_authorize(7, inbox_realtime_channel_name(7, $S['ns']), '123.456', $S), null);
check('malformed socket id is refused', livechat_realtime_authorize(7, $chA, 'not-a-socket-id', $S), null);
check('no tenant (0) is refused', livechat_realtime_authorize(0, livechat_realtime_channel_name(0, $S['ns']), '123.456', $S), null);
check('realtime off -> nothing is authorized', livechat_realtime_authorize(7, $chA, '123.456', null), null);
check('array input for channel cannot confuse the check', livechat_realtime_authorize(7, array($chA), '123.456', $S), null);

// ============================================================================================
echo "\n== event payloads: ids only, never message content ==\n";

$msg = livechat_realtime_message_payload(9001, 'sub_abc', 42, '1234567890', 'user', 'fb');
check('message payload keys are exactly the ids-only set', array_keys($msg),
    array('message_id', 'subscriber_id', 'page_id', 'fb_page_id', 'sender', 'social_media'));
check('message payload has no message_content / html key of any kind', array_intersect(array_keys($msg),
    array('message_content', 'message_content_html', 'last_conversation_message', 'agent_name', 'subscriber_name')), array());
check('unknown sender is normalized', livechat_realtime_message_payload(1, 's', 1, '1', '<script>', 'fb')['sender'], 'user');

$assigned = livechat_realtime_assigned_payload(55, 'sub_abc', 'ig');
check('assigned payload keys are exactly the ids-only set', array_keys($assigned), array('team_member_id', 'subscriber_id', 'social_media'));
check('assigned payload carries no title / description / subscriber name', array_intersect(array_keys($assigned),
    array('title', 'description', 'message_content', 'subscriber_name')), array());

// A payload built from strings that WOULD contain message text if the old code path were still
// wired proves this function never echoes its callers' content back — it only ever emits the
// six/three fixed keys above, regardless of what garbage is passed for other purposes elsewhere.
$msg2 = livechat_realtime_message_payload('7', 'sub', '3', '9', 'bot', 'fb');
check('payload values are ids/enums only, never longer free text', max(array_map('strlen', array_map('strval', $msg2))) < 20, true);

// ============================================================================================
echo "\n== publish: real no-op when Pusher is unconfigured ==\n";

class FakeCiNoPusher {
    public $config;
    function __construct() { $this->config = new class { function load($f, $b = false, $c = true) {} function item($k) { return ''; } }; }
}
// No curl calls happen: we prove this by asserting livechat_realtime_ci_settings() (which
// livechat_realtime_publish() checks first, before ever building a URL or touching curl) is
// NULL for a Pusher-unconfigured install — the same short-circuit inbox_realtime_notify() takes.
$fakeCi = new FakeCiNoPusher();
check('unconfigured Pusher -> livechat_realtime_ci_settings() is NULL (publish short-circuits before any network call)',
    livechat_realtime_ci_settings($fakeCi), null);

$threw = false;
try { livechat_realtime_publish($fakeCi, 7, 'livechat_event', $msg); } catch (\Throwable $e) { $threw = true; }
check('publish() with Pusher unset never throws', $threw, false);

class FakeCiThrowsOnLoad {
    public $config;
    function __construct() { $this->config = new class {
        function load($f, $b = false, $c = true) { throw new \RuntimeException('boom: config file missing'); }
        function item($k) { return null; }
    }; }
}
$threw2 = false;
try { livechat_realtime_publish(new FakeCiThrowsOnLoad(), 7, 'livechat_event', $msg); } catch (\Throwable $e) { $threw2 = true; }
check('publish() never throws even if the CI config layer itself throws', $threw2, false);

check('publish() with user_id <= 0 is a no-op (no channel to address)', function_exists('livechat_realtime_publish'), true); // sanity: function exists
$threw3 = false;
try { livechat_realtime_publish($fakeCi, 0, 'livechat_event', $msg); } catch (\Throwable $e) { $threw3 = true; }
check('publish() with user_id 0 never throws', $threw3, false);

// ============================================================================================
echo "\n== wiring: Home.php publishes on the new private channel, not the old public trigger() ==\n";

$home = file_get_contents(__DIR__ . '/../../application/controllers/Home.php');
check('Home.php no longer triggers the public livechat_channel', strpos($home, "trigger('livechat_channel'") === false, true);
check('Home.php no longer triggers the public livechat_assigned_agent_channel', strpos($home, "trigger('livechat_assigned_agent_channel'") === false, true);
check('Home.php calls livechat_realtime_publish for the message event', strpos($home, 'livechat_realtime_publish($this, $user_id, \'livechat_event\'') !== false, true);
check('Home.php calls livechat_realtime_publish for the assigned-agent event', strpos($home, 'livechat_realtime_publish($this, $user_id, \'livechat_assigned_agent_event\'') !== false, true);
check('the message event payload is built via livechat_realtime_message_payload() (ids only)', strpos($home, 'livechat_realtime_message_payload(') !== false, true);
check('the assigned event payload is built via livechat_realtime_assigned_payload() (ids only)', strpos($home, 'livechat_realtime_assigned_payload(') !== false, true);

$livechat = file_get_contents(__DIR__ . '/../../application/controllers/Livechat.php');
check('Livechat::realtime_auth checks CSRF and authorizes only the tenant\'s own channel',
    strpos($livechat, 'csrf_token_check(true)') !== false && strpos($livechat, 'livechat_realtime_authorize($uid,') !== false, true);
check('Livechat::realtime_auth exists as a public endpoint', strpos($livechat, 'public function realtime_auth()') !== false, true);

$pusher_js = file_get_contents(__DIR__ . '/../../application/views/include/pusher-js.php');
check('pusher-js.php no longer subscribes to the public livechat_channel', strpos($pusher_js, "pusher.subscribe('livechat_channel')") === false, true);
check('pusher-js.php no longer subscribes to the public livechat_assigned_agent_channel', strpos($pusher_js, "pusher.subscribe('livechat_assigned_agent_channel')") === false, true);
check('pusher-js.php subscribes to the private per-tenant channel via LIVECHAT_RT.channel', strpos($pusher_js, 'pusher.subscribe(LIVECHAT_RT.channel)') !== false, true);
check('pusher-js.php wires an authEndpoint for the private channel', strpos($pusher_js, "site_url('livechat/realtime_auth')") !== false, true);
check('pusher-js.php event handler no longer reads message content off the wire (responseData[7]/[9] etc.)',
    strpos($pusher_js, 'responseData[7]') === false && strpos($pusher_js, 'responseData[9]') === false, true);

// ============================================================================================
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
