<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Instagram API with Instagram Login: connect an Instagram professional account with no
 * Facebook Page. Endpoints (Meta docs, "Instagram API with Instagram Login"):
 *   authorize   https://www.instagram.com/oauth/authorize
 *   code->token POST https://api.instagram.com/oauth/access_token          (short-lived, 1h)
 *   long-lived  GET  graph.instagram.com/access_token?grant_type=ig_exchange_token   (60 days)
 *   refresh     GET  graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token
 *   webhooks    POST graph.instagram.com/<v>/me/subscribed_apps?subscribed_fields=...
 *
 * The connected account is stored as a normal IG row in facebook_rx_fb_page_info
 * (connection_type = 'ig_login'), so every existing IG feature finds it. Calls that go
 * through graph_url_group() are routed to graph.instagram.com by the token prefix
 * (fb_graph_helper); SDK-based reads in the comment path use Ig_login_client below.
 */

if (!defined('IGL_SCOPES')) define('IGL_SCOPES', 'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments');
if (!defined('IGL_WEBHOOK_FIELDS')) define('IGL_WEBHOOK_FIELDS', 'comments,messages,messaging_postbacks,messaging_referral,messaging_seen');
if (!defined('IGL_REFRESH_AFTER_DAYS')) define('IGL_REFRESH_AFTER_DAYS', 30);

if (!function_exists('igl_is_ig_token')) {
    function igl_is_ig_token($token) { return (bool) preg_match('/^IG[A-Za-z0-9]/', (string) $token); }
}

if (!function_exists('igl_config')) {
    /** @return array|null  ['app_id','app_secret'] when configured and enabled */
    function igl_config($db)
    {
        $prev = isset($db->db_debug) ? $db->db_debug : null;
        if ($prev !== null) $db->db_debug = false;
        try {
            $q = $db->query('SELECT app_id, app_secret_enc, enabled FROM ig_login_config ORDER BY id ASC LIMIT 1');
            if (!$q) return null;
            $r = $q->row_array();
            if (empty($r) || (int) $r['enabled'] !== 1 || trim((string) $r['app_id']) === '') return null;
            $secret = function_exists('secret_decrypt') ? (string) secret_decrypt((string) $r['app_secret_enc']) : '';
            if (trim($secret) === '') return null;
            return array('app_id' => trim((string) $r['app_id']), 'app_secret' => trim($secret));
        } catch (\Throwable $e) {
            return null;
        } finally {
            if ($prev !== null) $db->db_debug = $prev;
        }
    }
}

if (!function_exists('igl_authorize_url')) {
    function igl_authorize_url($app_id, $redirect_uri, $state)
    {
        return 'https://www.instagram.com/oauth/authorize?' . http_build_query(array(
            'client_id' => $app_id, 'redirect_uri' => $redirect_uri, 'response_type' => 'code',
            'scope' => IGL_SCOPES, 'state' => $state, 'enable_fb_login' => '0', 'force_reauth' => 'true',
        ));
    }
}

if (!function_exists('igl_http')) {
    /** Tiny HTTP wrapper. Tests replace it by defining $GLOBALS['igl_http_fake'] = callable. */
    function igl_http($method, $url, $post = null)
    {
        if (isset($GLOBALS['igl_http_fake']) && is_callable($GLOBALS['igl_http_fake'])) return call_user_func($GLOBALS['igl_http_fake'], $method, $url, $post);
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true));
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post === null ? '' : (is_array($post) ? http_build_query($post) : $post)); }
        elseif ($method === 'DELETE') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return array('error' => array('message' => 'network: ' . $err));
        $j = json_decode((string) $raw, true);
        return is_array($j) ? $j : array('error' => array('message' => 'bad response'));
    }
}

if (!function_exists('igl_err')) {
    function igl_err($r) { return is_array($r) && isset($r['error']) ? (is_array($r['error']) ? (string) ($r['error']['message'] ?? $r['error']['error_message'] ?? 'error') : (string) ($r['error_message'] ?? $r['error'])) : ''; }
}

if (!function_exists('igl_exchange_code')) {
    /**
     * code -> long-lived token + profile.
     * @return array ['ok'=>true,'token','expires_at','ig_id','username','name','picture','followers'] or ['ok'=>false,'error']
     */
    function igl_exchange_code(array $cfg, $code, $redirect_uri)
    {
        $short = igl_http('POST', 'https://api.instagram.com/oauth/access_token', array(
            'client_id' => $cfg['app_id'], 'client_secret' => $cfg['app_secret'], 'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect_uri, 'code' => $code,
        ));
        if (isset($short['data'][0])) $short = $short['data'][0];        // some API versions wrap it
        if (empty($short['access_token'])) return array('ok' => false, 'error' => igl_err($short) ?: 'code exchange failed');

        $long = igl_http('GET', 'https://graph.instagram.com/access_token?' . http_build_query(array(
            'grant_type' => 'ig_exchange_token', 'client_secret' => $cfg['app_secret'], 'access_token' => $short['access_token'])));
        if (empty($long['access_token'])) return array('ok' => false, 'error' => igl_err($long) ?: 'long-lived token failed');

        $me = igl_http('GET', ig_graph_url('me?' . http_build_query(array(
            'fields' => 'user_id,username,name,profile_picture_url,followers_count,media_count', 'access_token' => $long['access_token']))));
        if (empty($me['user_id']) && empty($me['id'])) return array('ok' => false, 'error' => igl_err($me) ?: 'profile read failed');

        return array('ok' => true, 'token' => $long['access_token'],
            'expires_at' => date('Y-m-d H:i:s', time() + max(3600, (int) ($long['expires_in'] ?? 5184000))),
            'ig_id' => (string) (!empty($me['user_id']) ? $me['user_id'] : $me['id']),
            'username' => (string) ($me['username'] ?? ''), 'name' => (string) ($me['name'] ?? ($me['username'] ?? '')),
            'picture' => (string) ($me['profile_picture_url'] ?? ''), 'followers' => (int) ($me['followers_count'] ?? 0),
            'media' => (int) ($me['media_count'] ?? 0));
    }
}

if (!function_exists('igl_subscribe_webhooks')) {
    function igl_subscribe_webhooks($token)
    {
        $r = igl_http('POST', ig_graph_url('me/subscribed_apps?' . http_build_query(array('subscribed_fields' => IGL_WEBHOOK_FIELDS, 'access_token' => $token))));
        return !empty($r['success']) ? '' : (igl_err($r) ?: 'subscribe failed');
    }
}

if (!function_exists('igl_refresh_token')) {
    /** @return array ['ok'=>true,'token','expires_at'] | ['ok'=>false,'error'] */
    function igl_refresh_token($token)
    {
        $r = igl_http('GET', 'https://graph.instagram.com/refresh_access_token?' . http_build_query(array('grant_type' => 'ig_refresh_token', 'access_token' => $token)));
        if (empty($r['access_token'])) return array('ok' => false, 'error' => igl_err($r) ?: 'refresh failed');
        $exp = (int) ($r['expires_in'] ?? 0);
        if ($exp <= 0 || $exp > 366 * 86400) $exp = 5184000;
        return array('ok' => true, 'token' => $r['access_token'], 'expires_at' => date('Y-m-d H:i:s', time() + $exp));
    }
}

if (!function_exists('igl_needs_refresh')) {
    function igl_needs_refresh($refreshed_at, $expires_at, $now = null)
    {
        $now = $now === null ? time() : $now;
        if ($expires_at && strtotime($expires_at) < $now) return false;                     // expired: must reconnect
        if ($expires_at && strtotime($expires_at) - $now < 15 * 86400) return true;         // close to expiry
        $base = $refreshed_at ? strtotime($refreshed_at) : 0;
        return $now - $base >= IGL_REFRESH_AFTER_DAYS * 86400;
    }
}

if (!function_exists('igl_upsert_account')) {
    /**
     * Store/refresh the connected account for $user_id. Returns page_info id.
     * Creates a facebook_rx_fb_user_info row too (config id 0) because the IG modules join through it.
     */
    function igl_upsert_account($db, $user_id, array $acc)
    {
        $user_id = (int) $user_id;
        $existing = $db->query("SELECT id, facebook_rx_fb_user_info_id, user_id FROM facebook_rx_fb_page_info WHERE instagram_business_account_id = ? AND deleted = '0' LIMIT 1", array($acc['ig_id']))->row_array();
        if (!empty($existing) && (int) $existing['user_id'] !== $user_id) return array('error' => 'This Instagram account is already connected to another Monkey Bot user.');

        $now = date('Y-m-d H:i:s');
        if (!empty($existing)) {
            $db->query('UPDATE facebook_rx_fb_user_info SET access_token = ?, name = ? WHERE id = ?', array($acc['token'], $acc['username'], (int) $existing['facebook_rx_fb_user_info_id']));
            $db->query("UPDATE facebook_rx_fb_page_info SET page_access_token = ?, connection_type = 'ig_login', ig_token_expires_at = ?, ig_token_refreshed_at = ?, ig_token_error = NULL,
                        insta_username = ?, username = ?, page_name = ?, page_profile = ?, insta_followers_count = ?, insta_media_count = ? WHERE id = ?",
                array($acc['token'], $acc['expires_at'], $now, $acc['username'], $acc['username'], $acc['name'], $acc['picture'], $acc['followers'], $acc['media'], (int) $existing['id']));
            return array('id' => (int) $existing['id'], 'new' => false);
        }
        $db->query("INSERT INTO facebook_rx_fb_user_info (facebook_rx_config_id, user_id, access_token, name, fb_id, add_date, deleted, need_to_delete) VALUES (0, ?, ?, ?, ?, ?, '0', '0')",
            array($user_id, $acc['token'], $acc['username'], 'ig_' . $acc['ig_id'], date('Y-m-d')));
        $uinfo = (int) $db->insert_id();
        $db->query("INSERT INTO facebook_rx_fb_page_info (user_id, facebook_rx_fb_user_info_id, page_id, page_name, username, page_profile, page_access_token, add_date, deleted,
                        bot_enabled, has_instagram, instagram_business_account_id, insta_username, insta_followers_count, insta_media_count,
                        connection_type, ig_token_expires_at, ig_token_refreshed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, '0', '1', '1', ?, ?, ?, ?, 'ig_login', ?, ?)",
            array($user_id, $uinfo, $acc['ig_id'], $acc['name'], $acc['username'], $acc['picture'], $acc['token'], date('Y-m-d'),
                  $acc['ig_id'], $acc['username'], $acc['followers'], $acc['media'], $acc['expires_at'], $now));
        return array('id' => (int) $db->insert_id(), 'new' => true);
    }
}

if (!function_exists('igl_refresh_all')) {
    /** cron_hub: refresh every IG Login token that is due. Never throws. */
    function igl_refresh_all($db)
    {
        $out = array('checked' => 0, 'refreshed' => 0, 'failed' => 0);
        try {
            if (!$db->table_exists('ig_login_config')) return array('skipped' => 'not installed');
            $rows = $db->query("SELECT id, facebook_rx_fb_user_info_id, page_access_token, ig_token_expires_at, ig_token_refreshed_at FROM facebook_rx_fb_page_info WHERE connection_type = 'ig_login' AND deleted = '0'")->result_array();
            foreach ($rows as $r) {
                $out['checked']++;
                if (!igl_needs_refresh($r['ig_token_refreshed_at'], $r['ig_token_expires_at'])) continue;
                $res = igl_refresh_token($r['page_access_token']);
                if ($res['ok']) {
                    $db->query('UPDATE facebook_rx_fb_page_info SET page_access_token = ?, ig_token_expires_at = ?, ig_token_refreshed_at = NOW(), ig_token_error = NULL WHERE id = ?', array($res['token'], $res['expires_at'], (int) $r['id']));
                    $db->query('UPDATE facebook_rx_fb_user_info SET access_token = ? WHERE id = ?', array($res['token'], (int) $r['facebook_rx_fb_user_info_id']));
                    $out['refreshed']++;
                } else {
                    $db->query('UPDATE facebook_rx_fb_page_info SET ig_token_error = ? WHERE id = ?', array(mb_substr($res['error'], 0, 500), (int) $r['id']));
                    $out['failed']++;
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }
}

if (!class_exists('Ig_login_client')) {
    /**
     * Stands in for $this->fb_rx_login in the Instagram comment/mention webhook when the
     * account is connected with Instagram Login. Same method names and return shapes as the
     * Fb_rx_login methods that path uses. Anything else throws, which the callers catch.
     */
    class Ig_login_client
    {
        private function get($path, $token, array $q = array())
        {
            $r = igl_http('GET', ig_graph_url($path . '?' . http_build_query($q + array('access_token' => $token))));
            if (igl_err($r) !== '') throw new Exception(igl_err($r));
            return $r;
        }
        private function post($path, $token, array $q = array())
        {
            $r = igl_http('POST', ig_graph_url($path . '?' . http_build_query(array('access_token' => $token))), $q);
            if (igl_err($r) !== '') throw new Exception(igl_err($r));
            return $r;
        }

        public function instagram_get_media_info_by_comment($comment_id, $token)
        {
            $r = $this->get($comment_id, $token, array('fields' => 'media,username,text,like_count,from'));
            if (empty($r['username']) && isset($r['from']['username'])) $r['username'] = $r['from']['username'];
            return $r;
        }
        public function instagram_get_all_comment_of_post($media_id, $token)
        {
            $r = $this->get($media_id . '/comments', $token, array('fields' => 'id,text,timestamp,username', 'limit' => 20));
            return isset($r['data']) && is_array($r['data']) ? $r['data'] : array();
        }
        public function instagram_get_media_url($user_id, $media_id, $token)
        {
            return $this->get($media_id, $token, array('fields' => 'id,media_type,media_url,owner,timestamp,permalink'));
        }
        public function instagram_auto_comment($message, $comment_id, $token)
        {
            return $this->post($comment_id . '/replies', $token, array('message' => $message));
        }
        public function instagram_hide_comment($comment_id, $token)
        {
            return $this->post($comment_id, $token, array('hide' => 'true'));
        }
        public function instagram_delete_comment($comment_id, $token)
        {
            $r = igl_http('DELETE', ig_graph_url($comment_id . '?' . http_build_query(array('access_token' => $token))));
            return $r;
        }
        public function get_private_reply_message_id_info($message_id, $token)
        {
            try { return $this->get($message_id, $token, array('fields' => 'to,from')); } catch (Exception $e) { return array(); }
        }
        public function get_postlist_from_instagram_account($ig_id, $token)
        {
            $r = $this->get('me/media', $token, array('fields' => 'id,timestamp,caption,like_count,comments_count,media_type,media_url,permalink,is_comment_enabled', 'limit' => 100));
            return array('data' => isset($r['data']) && is_array($r['data']) ? $r['data'] : array());
        }
        // Mentions use mentioned_comment / mentioned_media, which Instagram Login does not offer.
        public function instagram_get_all_comment_of_mention_post($u, $c, $t) { return array(); }
        public function instagram_get_all_comment_of_mention_caption($u, $m, $t) { return array(); }
        public function app_initialize($id) { return true; }

        public function __call($name, $args) { throw new Exception('Not available with Instagram Login: ' . $name); }
    }
}

if (!function_exists('igl_user_accounts')) {
    /** IG Login rows for a user (full rows), minus ids already listed. Column missing => none. */
    function igl_user_accounts($db, $user_id, array $already = array())
    {
        $prev = isset($db->db_debug) ? $db->db_debug : null;
        if ($prev !== null) $db->db_debug = false;
        try {
            $q = $db->query("SELECT * FROM facebook_rx_fb_page_info WHERE user_id = ? AND bot_enabled = '1' AND has_instagram = '1' AND deleted = '0' AND connection_type = 'ig_login'", array((int) $user_id));
            if (!$q) return array();
            $seen = array();
            foreach ($already as $a) $seen[(int) $a['id']] = true;
            $out = array();
            foreach ($q->result_array() as $r) if (!isset($seen[(int) $r['id']])) $out[] = $r;
            return $out;
        } catch (\Throwable $e) {
            return array();
        } finally {
            if ($prev !== null) $db->db_debug = $prev;
        }
    }
}
