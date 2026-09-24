<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Livechat realtime (Pusher) — private, per-tenant, ids-only. Sibling of
 * inbox_realtime_helper.php; follows the exact same pattern (see that file's header comment)
 * and reuses its signing/publish primitives, so publishing needs no Composer autoloader
 * (Ci_pusher, the vendor-Pusher-backed library, is not usable here — the app never loads
 * vendor/autoload.php) and degrades to a no-op exactly like Inbox does when Pusher is
 * unconfigured.
 *
 * - One PRIVATE channel per tenant: private-livechat-<install namespace>-<tenant user_id>.
 *   Both livechat events (new message, agent assignment) publish on this one channel.
 * - Payloads carry ids only (subscriber_id, page_id, message_id, team_member_id, sender,
 *   social_media) — never message content, subscriber names or rendered HTML. The browser
 *   re-fetches through the existing tenant-scoped Livechat endpoints (get_conversation_single).
 * - Publishing is best-effort: it never throws, never blocks the caller's own work (in a web
 *   request events are queued and sent once at shutdown; in CLI they are sent immediately),
 *   uses a short HTTP timeout, and after one failure stops trying for the rest of the request.
 * - Requires application/helpers/inbox_realtime_helper.php to be loaded (same CI helper
 *   autoloading applies); Livechat.php loads both.
 */

if (!function_exists('livechat_realtime_channel_name')) {
    /** Pure. The one channel a tenant's livechat events go to (and the only one it may subscribe to). */
    function livechat_realtime_channel_name($user_id, $ns)
    {
        $ns = preg_replace('/[^a-z0-9]/', '', strtolower((string) $ns));
        return 'private-livechat-' . $ns . '-' . (int) $user_id;
    }
}

if (!function_exists('livechat_realtime_authorize')) {
    /**
     * Pure. Pusher private-channel authorization for the logged-in tenant.
     * Returns array('auth' => '<key>:<hmac>') only when $channel is exactly this tenant's own
     * livechat channel and $socket_id is well-formed; NULL otherwise.
     */
    function livechat_realtime_authorize($user_id, $channel, $socket_id, $settings)
    {
        if (empty($settings) || (int) $user_id <= 0) return NULL;
        if (!is_string($channel) || !is_string($socket_id)) return NULL;
        if (!preg_match('/^\d+\.\d+$/', $socket_id)) return NULL;
        $own = livechat_realtime_channel_name($user_id, $settings['ns']);
        if (!hash_equals($own, $channel)) return NULL;
        return array('auth' => inbox_realtime_sign($settings['key'], $settings['secret'], $socket_id, $channel));
    }
}

if (!function_exists('livechat_realtime_message_payload')) {
    /** Pure. Ids only — no message content, no subscriber name, no rendered HTML. */
    function livechat_realtime_message_payload($message_id, $subscriber_id, $page_id, $fb_page_id, $sender, $social_media)
    {
        $sender = in_array($sender, array('user', 'bot', 'system'), true) ? $sender : 'user';
        return array(
            'message_id'    => (int) $message_id,
            'subscriber_id' => (string) $subscriber_id,
            'page_id'       => (int) $page_id,
            'fb_page_id'    => (string) $fb_page_id,
            'sender'        => $sender,
            'social_media'  => (string) $social_media,
        );
    }
}

if (!function_exists('livechat_realtime_assigned_payload')) {
    /** Pure. Ids only — no title, no message text, no subscriber name. */
    function livechat_realtime_assigned_payload($team_member_id, $subscriber_id, $social_media)
    {
        return array(
            'team_member_id' => (int) $team_member_id,
            'subscriber_id'  => (string) $subscriber_id,
            'social_media'   => (string) $social_media,
        );
    }
}

// ----------------------------------------------------------------------------- CI-bound part

if (!function_exists('livechat_realtime_ci_settings')) {
    /** Same Pusher config as Inbox; NULL means "realtime off". */
    function livechat_realtime_ci_settings($ci)
    {
        return inbox_realtime_ci_settings($ci);
    }
}

if (!function_exists('livechat_realtime_client_config')) {
    /** What the Livechat page needs to subscribe, or NULL (page then behaves as today). No secret. */
    function livechat_realtime_client_config($ci, $user_id)
    {
        $s = livechat_realtime_ci_settings($ci);
        if (empty($s) || (int) $user_id <= 0) return NULL;
        return array('key' => $s['key'], 'cluster' => $s['cluster'],
            'channel' => livechat_realtime_channel_name($user_id, $s['ns']));
    }
}

if (!function_exists('livechat_realtime_queue')) {
    /** The per-request queue and the "stop after one failure" switch. Mirrors inbox_realtime_queue(). */
    function &livechat_realtime_queue()
    {
        static $state = array('queue' => array(), 'broken' => FALSE, 'shutdown' => FALSE, 'transport' => NULL);
        return $state;
    }
}

if (!function_exists('livechat_realtime_enqueue')) {
    /**
     * Queue one ids-only event given already-resolved settings. Never throws, never outputs.
     * No-op when $settings is empty. Same timing contract as inbox_realtime_notify(): in a web
     * request the event is queued and the whole request's queue is flushed once at shutdown
     * (register_shutdown_function); in CLI it is sent immediately. This keeps a webhook's own
     * work from waiting on Pusher and coalesces bursts of events from one request into as few
     * HTTP calls as possible.
     *
     * Split out from livechat_realtime_publish() (which resolves settings from a CI instance)
     * so tests/livechat/run.php can exercise the queue/flush timing directly, without routing
     * through livechat_realtime_ci_settings()'s per-process cache (that cache is keyed to the
     * first $ci it ever sees — correct for one real request, awkward to juggle across several
     * fake CI objects in one test run).
     *
     * $sapi is test-only (defaults to PHP_SAPI); it lets tests exercise the "web request"
     * branch (queue, flush at shutdown) from a CLI test runner without touching real shutdown
     * functions. Production call sites never pass it.
     */
    function livechat_realtime_enqueue($settings, $user_id, $event_name, array $data, $sapi = null)
    {
        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0 || empty($settings)) return;

            $state = &livechat_realtime_queue();
            if ($state['broken']) return;

            $state['queue'][] = array(
                'channel' => livechat_realtime_channel_name($user_id, $settings['ns']),
                'name'    => $event_name,
                'data'    => $data,
            );

            $sapi = $sapi !== null ? $sapi : PHP_SAPI;
            if ($sapi === 'cli') { livechat_realtime_flush($settings); return; }
            if (!$state['shutdown']) {
                $state['shutdown'] = TRUE;
                register_shutdown_function('livechat_realtime_flush', $settings);
            }
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('error', 'livechat_realtime: publish skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('livechat_realtime_publish')) {
    /**
     * Publish one ids-only event on the tenant's private livechat channel, resolving Pusher
     * settings from the CI instance (livechat_realtime_ci_settings(), NULL means "off"). See
     * livechat_realtime_enqueue() for the queue/flush timing contract this delegates to.
     */
    function livechat_realtime_publish($ci, $user_id, $event_name, array $data, $sapi = null)
    {
        try {
            $s = livechat_realtime_ci_settings($ci);
            livechat_realtime_enqueue($s, $user_id, $event_name, $data, $sapi);
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('error', 'livechat_realtime: publish skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('livechat_realtime_flush')) {
    /**
     * Sends and clears the queue, in chunks of 10 (Pusher's batch_events limit). Never throws.
     * Uses the same curl-based batch request as Inbox (inbox_realtime_batch_request / short
     * timeout) so no vendor Pusher SDK is needed.
     */
    function livechat_realtime_flush($settings)
    {
        $state = &livechat_realtime_queue();
        $queue = $state['queue'];
        $state['queue'] = array();
        if (empty($queue) || $state['broken'] || empty($settings)) return;
        try {
            $send = $state['transport'];
            if ($send === NULL) {
                if (!function_exists('curl_init')) { $state['broken'] = TRUE; return; }
                $send = function (array $batch) use ($settings) {
                    $req = inbox_realtime_batch_request($settings, $batch, time());
                    $ch = curl_init($req['url']);
                    curl_setopt_array($ch, array(
                        CURLOPT_POST => TRUE, CURLOPT_POSTFIELDS => $req['body'], CURLOPT_RETURNTRANSFER => TRUE,
                        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                        CURLOPT_NOSIGNAL => TRUE,
                        CURLOPT_CONNECTTIMEOUT_MS => (int) (min(1.0, $settings['timeout']) * 1000),
                        CURLOPT_TIMEOUT_MS => (int) ($settings['timeout'] * 1000),
                    ));
                    curl_exec($ch);
                    $err = curl_error($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($err !== '' || $code < 200 || $code >= 300) throw new \RuntimeException($err !== '' ? $err : 'HTTP ' . $code);
                };
            }
            foreach (array_chunk($queue, 10) as $batch) $send($batch);
        } catch (\Throwable $e) {
            $state['broken'] = TRUE;
            if (function_exists('log_message')) log_message('error', 'livechat_realtime: publish failed: ' . $e->getMessage());
        }
    }
}
