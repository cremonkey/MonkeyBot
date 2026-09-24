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

if (!function_exists('livechat_realtime_publish')) {
    /**
     * Publish one ids-only event on the tenant's private livechat channel. Never throws, never
     * outputs. No-op when Pusher is unconfigured. Uses the same curl-based batch request as
     * Inbox (inbox_realtime_batch_request / short timeout) so no vendor Pusher SDK is needed.
     */
    function livechat_realtime_publish($ci, $user_id, $event_name, array $data)
    {
        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0) return;
            $s = livechat_realtime_ci_settings($ci);
            if (empty($s)) return;
            if (!function_exists('curl_init')) return;

            $batch = array(array(
                'channel' => livechat_realtime_channel_name($user_id, $s['ns']),
                'name'    => $event_name,
                'data'    => $data,
            ));
            $req = inbox_realtime_batch_request($s, $batch, time());
            $ch = curl_init($req['url']);
            curl_setopt_array($ch, array(
                CURLOPT_POST => TRUE, CURLOPT_POSTFIELDS => $req['body'], CURLOPT_RETURNTRANSFER => TRUE,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_NOSIGNAL => TRUE,
                CURLOPT_CONNECTTIMEOUT_MS => (int) (min(1.0, $s['timeout']) * 1000),
                CURLOPT_TIMEOUT_MS => (int) ($s['timeout'] * 1000),
            ));
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('error', 'livechat_realtime: publish failed: ' . $e->getMessage());
        }
    }
}
