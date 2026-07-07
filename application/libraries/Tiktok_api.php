<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TikTok API client for MonkeyBot TikTok Bot add-on.
 *
 * Two mutually exclusive modes, picked by config item `tiktok_api_mode`:
 *
 *  - 'display'  : TikTok for Developers (developers.tiktok.com) Login Kit +
 *                 Display API v2. Can connect an account and list videos, but
 *                 has NO comment scopes at all — comment auto-reply is
 *                 impossible in this mode.
 *  - 'business' : TikTok API for Business (business-api.tiktok.com) Business
 *                 Account APIs. Requires a TT4B developer app with the
 *                 Business Account / Comment Management products approved and
 *                 a TikTok *Business* account. Supports video list, comment
 *                 list and comment reply. Auth header is `Access-Token`, not
 *                 `Authorization: Bearer`.
 */
class Tiktok_api
{
    protected $ci;
    public $mode = 'display';

    // display (developers.tiktok.com)
    protected $client_key;
    protected $client_secret;
    protected $display_auth_url = 'https://www.tiktok.com/v2/auth/authorize/';
    protected $display_base = 'https://open.tiktokapis.com/v2';

    // business (business-api.tiktok.com)
    protected $business_app_id;
    protected $business_secret;
    protected $business_auth_url = 'https://business-api.tiktok.com/portal/auth';
    protected $business_base = 'https://business-api.tiktok.com/open_api/v1.3';

    public function __construct($config = array())
    {
        $this->ci =& get_instance();
        $this->mode            = in_array($this->ci->config->item('tiktok_api_mode'), array('display', 'business'), true) ? $this->ci->config->item('tiktok_api_mode') : 'display';
        $this->client_key      = !empty($config['client_key']) ? $config['client_key'] : $this->ci->config->item('tiktok_client_key');
        $this->client_secret   = !empty($config['client_secret']) ? $config['client_secret'] : $this->ci->config->item('tiktok_client_secret');
        $this->business_app_id = !empty($config['business_app_id']) ? $config['business_app_id'] : $this->ci->config->item('tiktok_business_app_id');
        $this->business_secret = !empty($config['business_secret']) ? $config['business_secret'] : $this->ci->config->item('tiktok_business_secret');
        $this->ci->load->helper('url');
    }

    /**
     * Authorization URL for the active mode.
     */
    public function get_authorize_url($redirect_uri, $state = '')
    {
        if ($this->mode === 'business') {
            $params = array(
                'app_id'       => $this->business_app_id,
                'state'        => $state,
                'redirect_uri' => $redirect_uri,
            );
            return $this->business_auth_url . '?' . http_build_query($params);
        }

        $params = array(
            'client_key'    => $this->client_key,
            'response_type' => 'code',
            'scope'         => 'user.info.basic,user.info.profile,video.list',
            'redirect_uri'  => $redirect_uri,
            'state'         => $state,
        );
        return $this->display_auth_url . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     * Display passes ?code=..., business passes ?auth_code=... — callers
     * should pass whichever they received.
     */
    public function get_access_token($code, $redirect_uri)
    {
        if ($this->mode === 'business') {
            $response = $this->http_post_json($this->business_base . '/oauth2/access_token/', array(
                'app_id'     => $this->business_app_id,
                'secret'     => $this->business_secret,
                'auth_code'  => $code,
                'grant_type' => 'authorization_code',
            ));
            return $this->_normalize_response($response, 'Token exchange failed');
        }

        $response = $this->http_post_form($this->display_base . '/oauth/token/', array(
            'client_key'    => $this->client_key,
            'client_secret' => $this->client_secret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirect_uri,
        ));
        return $this->_normalize_response($response, 'Token exchange failed');
    }

    /**
     * Refresh access token.
     */
    public function refresh_access_token($refresh_token)
    {
        if ($this->mode === 'business') {
            $response = $this->http_post_json($this->business_base . '/tt_user/oauth2/refresh_token/', array(
                'client_id'     => $this->business_app_id,
                'client_secret' => $this->business_secret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            ));
            return $this->_normalize_response($response, 'Refresh failed');
        }

        $response = $this->http_post_form($this->display_base . '/oauth/token/', array(
            'client_key'    => $this->client_key,
            'client_secret' => $this->client_secret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ));
        return $this->_normalize_response($response, 'Refresh failed');
    }

    /**
     * Fetch account profile.
     */
    public function get_user_info($access_token, $open_id)
    {
        if ($this->mode === 'business') {
            $url = $this->business_base . '/business/get/?' . http_build_query(array(
                'business_id' => $open_id,
                'fields'      => json_encode(array('username', 'display_name', 'profile_image', 'audience_countries')),
            ));
            $result = $this->_normalize_response($this->http_get($url, $access_token), 'User info failed');
            // reshape to the display-mode structure the controller expects
            if ($result['status'] == '1') {
                $d = $result['data'];
                $result['data'] = array('user' => array(
                    'display_name'    => isset($d['display_name']) ? $d['display_name'] : (isset($d['username']) ? $d['username'] : ''),
                    'avatar_url'      => isset($d['profile_image']) ? $d['profile_image'] : '',
                    'union_id'        => '',
                    'bio_description' => '',
                ));
            }
            return $result;
        }

        $url = $this->display_base . '/user/info/?fields=open_id,union_id,display_name,avatar_url,bio_description';
        return $this->_normalize_response($this->http_get($url, $access_token), 'User info failed');
    }

    /**
     * Fetch recent videos. Returns data.videos = array of {id/item_id, ...}.
     */
    public function get_videos($access_token, $open_id, $cursor = 0, $max_count = 20)
    {
        if ($this->mode === 'business') {
            $url = $this->business_base . '/business/video/list/?' . http_build_query(array(
                'business_id' => $open_id,
                'fields'      => json_encode(array('item_id', 'create_time', 'caption')),
                'cursor'      => (int)$cursor,
                'max_count'   => (int)$max_count,
            ));
            return $this->_normalize_response($this->http_get($url, $access_token), 'Video list failed');
        }

        // Display API v2: fields go in the query string, paging in the JSON body.
        $url = $this->display_base . '/video/list/?fields=id,title,create_time,cover_image_url';
        $payload = array('cursor' => (int)$cursor, 'max_count' => (int)$max_count);
        return $this->_normalize_response($this->http_post_json($url, $payload, $access_token), 'Video list failed');
    }

    /**
     * List comments on a video. Business mode only.
     */
    public function get_business_comments($access_token, $open_id, $video_id, $cursor = 0, $max_count = 50)
    {
        if ($this->mode !== 'business') {
            return array('status' => '0', 'message' => 'Comment listing requires TikTok API for Business (set tiktok_api_mode=business with an approved TT4B app).');
        }
        $url = $this->business_base . '/business/comment/list/?' . http_build_query(array(
            'business_id' => $open_id,
            'video_id'    => $video_id,
            'cursor'      => (int)$cursor,
            'max_count'   => (int)$max_count,
        ));
        return $this->_normalize_response($this->http_get($url, $access_token), 'Comment list failed');
    }

    /**
     * Reply to a comment. Business mode only.
     */
    public function reply_to_comment($access_token, $open_id, $comment_id, $text, $video_id = '')
    {
        if ($this->mode !== 'business') {
            return array('status' => '0', 'message' => 'Comment replies require TikTok API for Business (set tiktok_api_mode=business with an approved TT4B app).');
        }
        $payload = array(
            'business_id' => $open_id,
            'video_id'    => $video_id,
            'comment_id'  => $comment_id,
            'text'        => $text,
        );
        $response = $this->http_post_json($this->business_base . '/business/comment/reply/create/', $payload, $access_token);
        return $this->_normalize_response($response, 'Comment reply failed');
    }

    /**
     * Stub: list conversations. TikTok has no public DM API (partner-only).
     */
    public function get_conversations($access_token, $open_id, $cursor = 0, $max_count = 20)
    {
        return array('status' => '0', 'message' => 'TikTok Messaging API requires partner approval.');
    }

    /**
     * Stub: send direct message.
     */
    public function send_dm($access_token, $open_id, $to_open_id, $text)
    {
        return array('status' => '0', 'message' => 'TikTok Messaging API requires partner approval.');
    }

    /**
     * Normalize TikTok API responses to array(status, data|message).
     *
     * Success shapes seen across the two APIs:
     *   display v2 success: {data:{...}, error:{code:"ok", message:"", ...}}
     *   display v2 oauth:   {access_token:..., open_id:..., ...} (flat) or {error:"...", error_description:"..."}
     *   business:           {code:0, message:"OK", data:{...}}
     */
    protected function _normalize_response($response, $fallback_message)
    {
        if (empty($response) || !is_array($response)) {
            return array('status' => '0', 'message' => $fallback_message);
        }

        // flat oauth error: {"error":"invalid_grant","error_description":"..."}
        if (isset($response['error']) && is_string($response['error']) && $response['error'] !== '') {
            $message = isset($response['error_description']) ? $response['error_description'] : $response['error'];
            return array('status' => '0', 'message' => $message);
        }

        // structured error object; code "ok" / 0 means success
        if (isset($response['error']) && is_array($response['error'])) {
            $code = isset($response['error']['code']) ? $response['error']['code'] : 'ok';
            if ($code !== 'ok' && $code !== 0 && $code !== '0') {
                $message = !empty($response['error']['message']) ? $response['error']['message'] : $fallback_message;
                return array('status' => '0', 'message' => $message . ' (code: ' . $code . ')');
            }
            return array('status' => '1', 'data' => isset($response['data']) ? $response['data'] : $response);
        }

        // business envelope: {code:0|40xxx, message, data}
        if (isset($response['code'])) {
            if ((int)$response['code'] !== 0) {
                $message = !empty($response['message']) ? $response['message'] : $fallback_message;
                return array('status' => '0', 'message' => $message . ' (code: ' . $response['code'] . ')');
            }
            return array('status' => '1', 'data' => isset($response['data']) ? $response['data'] : $response);
        }

        return array('status' => '1', 'data' => isset($response['data']) ? $response['data'] : $response);
    }

    /**
     * HTTP GET. Sends both auth header styles; each API ignores the other's.
     */
    protected function http_get($url, $access_token)
    {
        return $this->_curl($url, null, $access_token, false);
    }

    /**
     * HTTP POST (form-encoded, no auth header — used by display oauth).
     */
    protected function http_post_form($url, $payload)
    {
        return $this->_curl($url, http_build_query($payload), '', true, 'application/x-www-form-urlencoded');
    }

    /**
     * HTTP POST (JSON).
     */
    protected function http_post_json($url, $payload, $access_token = '')
    {
        return $this->_curl($url, json_encode($payload), $access_token, true, 'application/json');
    }

    protected function _curl($url, $body, $access_token, $is_post, $content_type = '')
    {
        $headers = array();
        if ($content_type !== '') $headers[] = 'Content-Type: ' . $content_type;
        if (!empty($access_token)) {
            if ($this->mode === 'business') {
                $headers[] = 'Access-Token: ' . $access_token;
            } else {
                $headers[] = 'Authorization: Bearer ' . $access_token;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ));
        if ($is_post) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return array('error' => array('code' => 'curl', 'message' => $err));
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            log_message('error', 'Tiktok_api non-JSON response from ' . $url . ': ' . substr((string)$raw, 0, 300));
            return array('error' => array('code' => 'badjson', 'message' => 'Non-JSON response from TikTok'));
        }
        return $decoded;
    }
}
