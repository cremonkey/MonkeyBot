<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-23 — mirror every AI-captured lead into an external CRM (8xCRM).
 *
 * Runs from the same hook as the Google Sheet sync, at the end of Ai_tools::t_save_lead,
 * and FAILS OPEN: a slow or broken CRM must never delay or swallow the customer's reply.
 * The lead is already safe in our own crm_deals by the time this runs, so the worst case
 * is a row in crm_external_log with the error.
 *
 * 8xCRM specifics learned by probing the live API (2026-07-16):
 *  - The API host is byootbay.8xcrm.COM. The docs are hosted on .NET, but that host
 *    serves the Angular SPA — POSTing /oauth/token there returns HTML, not a token.
 *  - grant_type=password needs client_id + client_secret + username + password. The
 *    client pair alone returns {"error":"invalid_client"}.
 *  - Token: token_type=Bearer, expires_in=2592000 (30 days). We re-run the password
 *    grant when it expires rather than using refresh_token — one code path, and a
 *    30-day token means it happens ~monthly.
 *  - storeLead answers {"status":true,"data":{"id":<lead id>}} and accepts UTF-8 Arabic
 *    in `description` as-is.
 */

if (!function_exists('xcrm_get_config')) {
    /** Active, fully-configured external CRM row for a user, or null. */
    function xcrm_get_config($user_id)
    {
        $ci = &get_instance();
        if (!$ci->db->table_exists('crm_external_config')) return null;
        $row = $ci->db->from('crm_external_config')->where('user_id', (int) $user_id)->get()->row_array();
        if (empty($row) || $row['status'] !== '1') return null;
        foreach (array('base_url', 'client_id', 'client_secret', 'username', 'password', 'form_id') as $f) {
            if (empty($row[$f])) return null;
        }
        return $row;
    }
}

if (!function_exists('xcrm_http')) {
    /**
     * POST JSON. Returns array(http_code, decoded_body|null, raw). Never throws.
     * Short timeouts: this sits behind a live chat reply.
     */
    function xcrm_http($url, $payload, $headers = array(), $timeout = 12)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array(
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MonkeyBot/Web',
        ), $headers));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return array(0, null, 'curl: ' . $err);
        }
        curl_close($ch);
        return array($code, json_decode($raw, true), $raw);
    }
}

if (!function_exists('xcrm_token')) {
    /**
     * A valid access token, from cache or a fresh password grant.
     * Returns "<type> <token>" ready for the Authorization header, or null.
     */
    function xcrm_token($config, $force = false)
    {
        $ci = &get_instance();

        if (!$force && !empty($config['access_token']) && !empty($config['token_expires_at'])) {
            // 5-minute skew so we never send a token that expires mid-flight
            if (strtotime($config['token_expires_at']) > time() + 300) {
                return trim(($config['token_type'] ?: 'Bearer') . ' ' . $config['access_token']);
            }
        }

        list($code, $body) = xcrm_http(rtrim($config['base_url'], '/') . '/oauth/token', array(
            'grant_type'    => 'password',
            'client_id'     => (string) $config['client_id'],
            'client_secret' => (string) $config['client_secret'],
            'username'      => (string) $config['username'],
            'password'      => (string) $config['password'],
        ), array(), 20);

        if ($code !== 200 || empty($body['access_token'])) {
            $msg = isset($body['error_description']) ? $body['error_description']
                 : (isset($body['message']) ? $body['message'] : 'HTTP ' . $code);
            xcrm_note_error($config['user_id'], 'token: ' . $msg);
            return null;
        }

        $expires = time() + (int) ($body['expires_in'] ?? 3600);
        $ci->db->where('user_id', (int) $config['user_id'])->update('crm_external_config', array(
            'access_token'     => $body['access_token'],
            'token_type'       => $body['token_type'] ?: 'Bearer',
            'token_expires_at' => date('Y-m-d H:i:s', $expires),
            'last_error'       => null,
        ));
        return trim(($body['token_type'] ?: 'Bearer') . ' ' . $body['access_token']);
    }
}

if (!function_exists('xcrm_note_error')) {
    function xcrm_note_error($user_id, $msg)
    {
        $ci = &get_instance();
        @$ci->db->where('user_id', (int) $user_id)->update('crm_external_config',
            array('last_error' => mb_substr((string) $msg, 0, 500)));
    }
}

if (!function_exists('xcrm_split_name')) {
    /** 8xCRM wants first/last separately as well as full_name. */
    function xcrm_split_name($full)
    {
        $full = trim(preg_replace('/\s+/u', ' ', (string) $full));
        if ($full === '') return array('', '', '');
        $parts = explode(' ', $full);
        $first = array_shift($parts);
        $last  = $parts ? array_pop($parts) : '';
        $middle = $parts ? implode(' ', $parts) : '';
        return array($first, $middle, $last);
    }
}

if (!function_exists('xcrm_build_payload')) {
    /** Map our lead onto 8xCRM's storeLead body. */
    function xcrm_build_payload($config, $lead)
    {
        $phone = trim((string) ($lead['phone'] ?? ''));
        $email = trim((string) ($lead['email'] ?? ''));
        $name  = trim((string) ($lead['name'] ?? ''));
        if ($name === '') $name = $phone !== '' ? $phone : $email;
        list($first, $middle, $last) = xcrm_split_name($name);

        // 8xCRM validates description at 191 chars (verified 2026-07-16: 191 accepted,
        // 192 -> 422 "may not be greater than 191 characters" — a Laravel default string
        // column). The full note therefore CANNOT go here; it stays complete on our own
        // deal. Pack the most actionable parts first and cut cleanly, so a rep opening
        // the lead still sees where the conversation got to.
        $bits = array();
        if (!empty($lead['status']))  $bits[] = trim((string) $lead['status']);
        if (!empty($lead['summary'])) $bits[] = trim((string) $lead['summary']);
        if (!empty($lead['profile'])) $bits[] = trim((string) $lead['profile']);
        $tail = 'Deal #' . (int) ($lead['deal_id'] ?? 0);
        $channel = strtoupper((string) ($lead['source'] ?? ''));
        if ($channel !== '') $tail = $channel . ' | ' . $tail;

        $desc = trim(implode(' — ', array_filter($bits)));
        $room = 191 - (mb_strlen($tail) + 3);          // " | " before the tail
        if (mb_strlen($desc) > $room) $desc = trim(mb_substr($desc, 0, $room - 1)) . '…';
        $desc = trim($desc . ' | ' . $tail);
        if (mb_strlen($desc) > 191) $desc = mb_substr($desc, 0, 191);

        $payload = array(
            'title' => '', 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last,
            'full_name' => $name, 'description' => $desc,
            'company' => '', 'address' => '', 'zip_code' => '', 'birthdate' => '',
            'phones' => array(), 'social_accounts' => array(),
            'form_id' => (string) $config['form_id'],
        );
        if ($phone !== '') {
            $payload['phones'][] = array('phone' => $phone,
                'country_code' => $config['default_country_code'] ?: 'EG');
        }
        if ($email !== '') {
            $payload['social_accounts'][] = array('social_account' => $email,
                'account_type_id' => (int) $config['email_account_type_id']);
        }
        return $payload;
    }
}

if (!function_exists('xcrm_store_lead')) {
    /**
     * ENQUEUE a lead. Returns true if it was queued.
     *
     * This deliberately does NOT call 8xCRM. Their storeLead measured 10-14 seconds
     * (2026-07-16), and this runs inside Ai_tools::t_save_lead — i.e. while the customer
     * is waiting for the bot's reply. Pushing inline would stall every lead-capturing
     * reply by 10s+ and still fail intermittently on any timeout worth having. Cron_hub
     * drains the queue every 5 minutes, which also gives retries for free.
     */
    function xcrm_store_lead($user_id, $lead)
    {
        $ci = &get_instance();
        try {
            $config = xcrm_get_config($user_id);
            if (!$config) return false;
            if (!$ci->db->table_exists('crm_external_log')) return false;

            // Nothing to file without a way to reach the person.
            $phone = trim((string) ($lead['phone'] ?? ''));
            $email = trim((string) ($lead['email'] ?? ''));
            if ($phone === '' && $email === '') return false;

            // One queue row per deal: t_save_lead also runs on updates to an open deal,
            // and the sales team should not get the same lead twice.
            if (!empty($lead['deal_id'])) {
                $dup = $ci->db->from('crm_external_log')
                    ->where('user_id', (int) $user_id)->where('deal_id', (int) $lead['deal_id'])
                    ->where_in('status', array('pending', 'sent'))->count_all_results();
                if ($dup > 0) return false;
            }

            $ci->db->insert('crm_external_log', array(
                'user_id'         => (int) $user_id,
                'deal_id'         => !empty($lead['deal_id']) ? (int) $lead['deal_id'] : null,
                'provider'        => '8xcrm',
                'status'          => 'pending',
                'attempts'        => 0,
                'next_attempt_at' => date('Y-m-d H:i:s'),
                'payload'         => json_encode(xcrm_build_payload($config, $lead), JSON_UNESCAPED_UNICODE),
                'phone'           => mb_substr($phone, 0, 60),
                'created_at'      => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (Exception $e) {
            log_message('error', 'xcrm_store_lead(enqueue): ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('xcrm_drain')) {
    /**
     * Push queued leads. Called from Cron_hub, never from a reply path, so it can afford
     * a real timeout. Returns a small summary array.
     *
     * Backs off 5/25/125 minutes and gives up after 5 attempts so a permanently rejected
     * lead cannot spin forever — it lands as 'failed' with the error kept for the owner.
     */
    function xcrm_drain($limit = 20)
    {
        $ci = &get_instance();
        $out = array('sent' => 0, 'failed' => 0, 'retry' => 0);
        if (!$ci->db->table_exists('crm_external_log')) return $out;

        // Recover rows stuck in 'processing' from a drain that died mid-flight. Claim sets
        // next_attempt_at = claim time, so a processing row older than 10 min was orphaned.
        $ci->db->where('status', 'processing')->where('next_attempt_at <', date('Y-m-d H:i:s', time() - 600))
            ->set('status', 'pending')->set('error', null)->update('crm_external_log');

        // Claim rows atomically before sending: flip pending -> processing on exactly the
        // rows we'll handle (stamping next_attempt_at = now as the claim time), then read
        // back only what THIS drain claimed. Two overlapping drains can't both pick the
        // same lead and double-POST it. (The cron GET_LOCK already prevents overlap; this
        // is defense-in-depth and also covers a manual backfill running with the cron.)
        $now = date('Y-m-d H:i:s');
        $claim = mb_substr(md5(uniqid('xd', true)), 0, 16);
        $ci->db->where('status', 'pending')
            ->group_start()->where('next_attempt_at <=', $now)->or_where('next_attempt_at', null)->group_end()
            ->order_by('id', 'ASC')->limit((int) $limit)
            ->set('status', 'processing')->set('error', $claim)->set('next_attempt_at', $now)
            ->update('crm_external_log');
        $rows = $ci->db->from('crm_external_log')->where('status', 'processing')->where('error', $claim)
            ->order_by('id', 'ASC')->get()->result_array();
        if (empty($rows)) return $out;

        $tokens = array();   // one token per user per drain
        foreach ($rows as $row) {
            $user_id = (int) $row['user_id'];
            $config = xcrm_get_config($user_id);
            if (!$config) continue;

            if (!isset($tokens[$user_id])) $tokens[$user_id] = xcrm_token($config);
            $auth = $tokens[$user_id];
            $attempts = (int) $row['attempts'] + 1;

            if ($auth === null) {
                xcrm_defer($row, $attempts, 'no token', $out);
                continue;
            }

            $url = rtrim($config['base_url'], '/') . '/api/v1/lead_generation/web_form_routings/storeLead';
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                $ci->db->where('id', $row['id'])->update('crm_external_log',
                    array('status' => 'failed', 'attempts' => $attempts, 'error' => 'unreadable payload'));
                $out['failed']++;
                continue;
            }

            // 40s: their API measured 10-14s and this is not on anyone's reply path.
            list($code, $body, $raw) = xcrm_http($url, $payload, array('Authorization: ' . $auth), 40);

            // A cached token can be revoked before it expires; refresh once and retry.
            if ($code === 401) {
                $fresh = $ci->db->from('crm_external_config')->where('user_id', $user_id)->get()->row_array();
                $auth = xcrm_token($fresh, true);
                $tokens[$user_id] = $auth;
                if ($auth !== null) list($code, $body, $raw) = xcrm_http($url, $payload, array('Authorization: ' . $auth), 40);
            }

            $ok = ($code >= 200 && $code < 300) && !empty($body['status']);
            if ($ok) {
                $ci->db->where('id', $row['id'])->update('crm_external_log', array(
                    'status'    => 'sent', 'ok' => '1', 'attempts' => $attempts, 'http_code' => (int) $code,
                    'remote_id' => isset($body['data']['id']) ? (string) $body['data']['id'] : null,
                    'error'     => null,
                ));
                $ci->db->where('user_id', $user_id)->set('sent_count', 'sent_count+1', false)
                    ->update('crm_external_config', array('last_sync_at' => date('Y-m-d H:i:s'), 'last_error' => null));
                $out['sent']++;
            } else {
                $err = isset($body['message']) ? $body['message'] : ('HTTP ' . $code . ' ' . mb_substr((string) $raw, 0, 200));
                xcrm_defer($row, $attempts, $err, $out, $code);
                xcrm_note_error($user_id, $err);
            }
        }
        return $out;
    }
}

if (!function_exists('xcrm_defer')) {
    function xcrm_defer($row, $attempts, $err, &$out, $code = 0)
    {
        $ci = &get_instance();
        $give_up = $attempts >= 5;
        $delay_min = array(1 => 5, 2 => 25, 3 => 125, 4 => 125);
        $ci->db->where('id', $row['id'])->update('crm_external_log', array(
            'status'          => $give_up ? 'failed' : 'pending',
            'attempts'        => $attempts,
            'http_code'       => (int) $code,
            'error'           => mb_substr((string) $err, 0, 500),
            'next_attempt_at' => $give_up ? null
                : date('Y-m-d H:i:s', time() + 60 * ($delay_min[$attempts] ?? 125)),
        ));
        $out[$give_up ? 'failed' : 'retry']++;
    }
}
