<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Unified Inbox realtime (Pusher) — channel-agnostic, tenant-scoped "a message landed in
 * conversation X" events. See docs/product/unified-inbox.md, "Realtime (Pusher)".
 *
 * - One PRIVATE channel per tenant: private-inbox-<install namespace>-<tenant user_id>.
 *   The namespace is derived from the install's encryption_key so two installs sharing one
 *   Pusher app never share a channel, and a channel name is not guessable from a user id.
 * - The event carries ids only (conversation_id, message_id, sender) — never message content.
 *   The browser re-fetches through the existing tenant-scoped Inbox endpoints.
 * - Publishing is best-effort: it never throws, never blocks the webhook's own work (in a web
 *   request events are queued and sent once at shutdown; in CLI they are sent immediately),
 *   uses a short HTTP timeout, and after one failure stops trying for the rest of the request.
 * - When Pusher is not configured (app id / key / secret empty) or the kill switch
 *   config item `inbox_realtime_enabled` is FALSE, every function here is a no-op and the
 *   Inbox page behaves exactly as without this feature.
 *
 * The pure functions (no CI instance) are exercised by tests/inbox/run.php.
 */

if (!function_exists('inbox_realtime_settings')) {
    /**
     * Pure. $cfg: pusher_app_id, pusher_app_key, pusher_app_secret, pusher_cluster,
     * encryption_key, inbox_realtime_enabled, inbox_realtime_timeout.
     * Returns NULL when realtime is off, else the normalized settings.
     */
    function inbox_realtime_settings(array $cfg)
    {
        if (array_key_exists('inbox_realtime_enabled', $cfg) && $cfg['inbox_realtime_enabled'] === FALSE) return NULL;
        $id = trim((string) ($cfg['pusher_app_id'] ?? ''));
        $key = trim((string) ($cfg['pusher_app_key'] ?? ''));
        $secret = trim((string) ($cfg['pusher_app_secret'] ?? ''));
        if ($id === '' || $key === '' || $secret === '') return NULL;
        $timeout = (float) ($cfg['inbox_realtime_timeout'] ?? 0);
        if ($timeout <= 0 || $timeout > 5) $timeout = 2.0;
        return array(
            'app_id'  => $id,
            'key'     => $key,
            'secret'  => $secret,
            'cluster' => trim((string) ($cfg['pusher_cluster'] ?? '')),
            'timeout' => $timeout,
            'ns'      => inbox_realtime_namespace((string) ($cfg['encryption_key'] ?? '')),
            // optional self-hosted / test endpoint, the same items Ci_pusher already honours
            'host'    => trim((string) ($cfg['pusher_host'] ?? '')),
            'port'    => (int) ($cfg['pusher_port'] ?? 0),
            'scheme'  => in_array(($cfg['pusher_scheme'] ?? ''), array('http', 'https'), true) ? $cfg['pusher_scheme'] : '',
        );
    }
}

if (!function_exists('inbox_realtime_namespace')) {
    function inbox_realtime_namespace($encryption_key)
    {
        return substr(hash('sha256', 'inbox-realtime|' . (string) $encryption_key), 0, 12);
    }
}

if (!function_exists('inbox_realtime_channel_name')) {
    /** Pure. The one channel a tenant's events go to (and the only one it may subscribe to). */
    function inbox_realtime_channel_name($user_id, $ns)
    {
        $ns = preg_replace('/[^a-z0-9]/', '', strtolower((string) $ns));
        return 'private-inbox-' . $ns . '-' . (int) $user_id;
    }
}

if (!function_exists('inbox_realtime_authorize')) {
    /**
     * Pure. Pusher private-channel authorization for the logged-in tenant.
     * Returns array('auth' => '<key>:<hmac>') only when $channel is exactly this tenant's own
     * channel and $socket_id is well-formed; NULL otherwise (another tenant's channel, a
     * presence/public channel, a crafted name, realtime off, no tenant).
     * Signature per the Pusher protocol: HMAC-SHA256(secret, "<socket_id>:<channel>").
     */
    function inbox_realtime_authorize($user_id, $channel, $socket_id, $settings)
    {
        if (empty($settings) || (int) $user_id <= 0) return NULL;
        if (!is_string($channel) || !is_string($socket_id)) return NULL;
        if (!preg_match('/^\d+\.\d+$/', $socket_id)) return NULL;
        $own = inbox_realtime_channel_name($user_id, $settings['ns']);
        if (!hash_equals($own, $channel)) return NULL;
        return array('auth' => inbox_realtime_sign($settings['key'], $settings['secret'], $socket_id, $channel));
    }
}

if (!function_exists('inbox_realtime_sign')) {
    /** Pure. Pusher channel-auth token: "<key>:" . HMAC-SHA256(secret, "<socket_id>:<channel>"). */
    function inbox_realtime_sign($key, $secret, $socket_id, $channel)
    {
        return $key . ':' . hash_hmac('sha256', $socket_id . ':' . $channel, $secret);
    }
}

if (!function_exists('inbox_realtime_event_payload')) {
    /** Pure. Ids only — message content never leaves the server through Pusher. */
    function inbox_realtime_event_payload($conversation_id, $message_id, $sender)
    {
        $sender = in_array($sender, array('user', 'bot', 'system'), true) ? $sender : 'user';
        return array('conversation_id' => (int) $conversation_id, 'message_id' => (int) $message_id, 'sender' => $sender);
    }
}

if (!function_exists('inbox_realtime_batch')) {
    /**
     * Pure. Turns the queued rows into Pusher batch events: one per conversation (the newest
     * message wins — the browser re-fetches the whole thread anyway), each on its own
     * tenant's channel, in chunks of 10 (Pusher's batch limit).
     */
    function inbox_realtime_batch(array $queue, $ns)
    {
        $by_conv = array();
        foreach ($queue as $q) {
            if ((int) $q['user_id'] <= 0 || (int) $q['conversation_id'] <= 0) continue;
            $k = (int) $q['user_id'] . ':' . (int) $q['conversation_id'];
            if (!isset($by_conv[$k]) || (int) $q['message_id'] > $by_conv[$k]['data']['message_id']) {
                $by_conv[$k] = array(
                    'channel' => inbox_realtime_channel_name($q['user_id'], $ns),
                    'name'    => 'message',
                    'data'    => inbox_realtime_event_payload($q['conversation_id'], $q['message_id'], $q['sender']),
                );
            }
        }
        return array_chunk(array_values($by_conv), 10);
    }
}

if (!function_exists('inbox_realtime_poll_intervals')) {
    /** Pure. Browser refresh intervals in seconds: [without realtime, with realtime connected]. */
    function inbox_realtime_poll_intervals($fallback, $with_realtime)
    {
        $fallback = (int) $fallback; $with_realtime = (int) $with_realtime;
        if ($fallback <= 0) $fallback = 20;
        if ($with_realtime <= 0) $with_realtime = 90;
        return array(max(5, $fallback), max(15, $with_realtime));
    }
}

// ----------------------------------------------------------------------------- CI-bound part

if (!function_exists('inbox_realtime_ci_settings')) {
    /** Reads the flags once per request. Never throws; NULL means "realtime off". */
    function inbox_realtime_ci_settings($ci)
    {
        static $cache = FALSE;
        if ($cache !== FALSE) return $cache;
        $cache = NULL;
        try {
            $ci->config->load('pusher', FALSE, TRUE); // fail gracefully: a missing file means "off"
            $cfg = array();
            foreach (array('pusher_app_id', 'pusher_app_key', 'pusher_app_secret', 'pusher_cluster',
                           'pusher_host', 'pusher_port', 'pusher_scheme',
                           'encryption_key', 'inbox_realtime_enabled', 'inbox_realtime_timeout') as $k) {
                $v = $ci->config->item($k);
                if ($v !== NULL) $cfg[$k] = $v;
            }
            $cache = inbox_realtime_settings($cfg);
        } catch (\Throwable $e) {
            $cache = NULL;
        }
        return $cache;
    }
}

if (!function_exists('inbox_realtime_client_config')) {
    /** What the Inbox page needs to subscribe, or NULL (page then only polls). No secret. */
    function inbox_realtime_client_config($ci, $user_id)
    {
        $s = inbox_realtime_ci_settings($ci);
        if (empty($s) || (int) $user_id <= 0) return NULL;
        return array('key' => $s['key'], 'cluster' => $s['cluster'],
            'channel' => inbox_realtime_channel_name($user_id, $s['ns']));
    }
}

if (!function_exists('inbox_realtime_queue')) {
    /** The per-request queue and the "stop after one failure" switch. */
    function &inbox_realtime_queue()
    {
        static $state = array('queue' => array(), 'broken' => FALSE, 'shutdown' => FALSE, 'transport' => NULL);
        return $state;
    }
}

if (!function_exists('inbox_realtime_notify')) {
    /**
     * Call right after a livechat_messages row is inserted (the BEFORE INSERT trigger has
     * already linked it to a conversation). Never throws, never outputs, never changes the
     * caller's query-builder state (raw query, db_debug off for the one lookup).
     */
    function inbox_realtime_notify($ci, $message_id)
    {
        try {
            $message_id = (int) $message_id;
            if ($message_id <= 0) return;
            $s = inbox_realtime_ci_settings($ci);
            if (empty($s)) return;
            $state = &inbox_realtime_queue();
            if ($state['broken']) return;

            $debug = $ci->db->db_debug;
            $ci->db->db_debug = FALSE;
            $q = $ci->db->query('SELECT lm.conversation_id, lm.sender, c.user_id FROM livechat_messages lm
                                 JOIN conversations c ON c.id = lm.conversation_id WHERE lm.id = ' . $message_id . ' LIMIT 1');
            $ci->db->db_debug = $debug;
            $row = $q ? $q->row_array() : NULL;
            if (empty($row) || empty($row['conversation_id'])) return;

            $state['queue'][] = array('user_id' => (int) $row['user_id'], 'conversation_id' => (int) $row['conversation_id'],
                'message_id' => $message_id, 'sender' => $row['sender']);

            if (PHP_SAPI === 'cli') { inbox_realtime_flush($s); return; }
            if (!$state['shutdown']) {
                $state['shutdown'] = TRUE;
                register_shutdown_function('inbox_realtime_flush', $s);
            }
        } catch (\Throwable $e) {
            if (isset($debug)) $ci->db->db_debug = $debug;
            if (function_exists('log_message')) log_message('error', 'inbox_realtime: notify skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('inbox_realtime_batch_request')) {
    /**
     * Pure. The signed Pusher Channels HTTP API request for one batch (POST
     * /apps/<id>/batch_events), built here instead of through vendor/pusher so publishing needs
     * no Composer autoloader (this app does not load one) and can use hard curl timeouts.
     * Signing per the Pusher REST API: HMAC-SHA256(secret, "POST\n<path>\n<sorted query>").
     */
    function inbox_realtime_batch_request($settings, array $batch, $timestamp)
    {
        $events = array();
        foreach ($batch as $ev) $events[] = array('name' => $ev['name'], 'data' => json_encode($ev['data']), 'channel' => $ev['channel']);
        $body = json_encode(array('batch' => $events));
        $path = '/apps/' . rawurlencode($settings['app_id']) . '/batch_events';
        $params = array('auth_key' => $settings['key'], 'auth_timestamp' => (string) (int) $timestamp,
            'auth_version' => '1.0', 'body_md5' => md5($body));
        ksort($params);
        $pairs = array();
        foreach ($params as $k => $v) $pairs[] = $k . '=' . $v;
        $params['auth_signature'] = hash_hmac('sha256', "POST\n" . $path . "\n" . implode('&', $pairs), $settings['secret']);
        $scheme = ($settings['scheme'] ?? '') !== '' ? $settings['scheme'] : 'https';
        $host = ($settings['host'] ?? '') !== '' ? $settings['host']
            : 'api-' . (($settings['cluster'] ?? '') !== '' ? $settings['cluster'] : 'mt1') . '.pusher.com';
        $port = (int) ($settings['port'] ?? 0) > 0 ? (int) $settings['port'] : ($scheme === 'http' ? 80 : 443);
        return array('url' => $scheme . '://' . $host . ':' . $port . $path . '?' . http_build_query($params), 'body' => $body);
    }
}

if (!function_exists('inbox_realtime_flush')) {
    /** Sends and clears the queue. Never throws. */
    function inbox_realtime_flush($settings)
    {
        $state = &inbox_realtime_queue();
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
            foreach (inbox_realtime_batch($queue, $settings['ns']) as $batch) $send($batch);
        } catch (\Throwable $e) {
            $state['broken'] = TRUE;
            if (function_exists('log_message')) log_message('error', 'inbox_realtime: publish failed: ' . $e->getMessage());
        }
    }
}
