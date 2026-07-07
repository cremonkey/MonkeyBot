<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * CRM -> Google Sheet lead sync.
 *
 * Uses a Google *service account* (JWT bearer flow over plain curl) instead of
 * the composer google/apiclient: vendor platform_check requires PHP >= 8.1 and
 * this container runs 7.4, and OAuth refresh tokens from a "testing"-mode
 * consent screen expire every 7 days. The user shares the target spreadsheet
 * with the service account's client_email (Editor) and pastes the JSON key in
 * CRM -> Google Sheet Sync.
 */

if (!function_exists('crm_sheet_get_config')) {
    /**
     * Active, fully-configured sheet config row for a user, or null.
     */
    function crm_sheet_get_config($user_id)
    {
        $ci = &get_instance();
        if (!$ci->db->table_exists('crm_sheet_config')) return null;
        $row = $ci->db->from('crm_sheet_config')->where('user_id', (int) $user_id)->get()->row_array();
        if (empty($row) || $row['status'] !== '1') return null;
        if (empty($row['service_account_json']) || empty($row['spreadsheet_id'])) return null;
        return $row;
    }
}

if (!function_exists('crm_sheet_parse_sa')) {
    /**
     * Decode + validate a service account JSON key. Returns array or null.
     */
    function crm_sheet_parse_sa($json)
    {
        $sa = json_decode((string) $json, true);
        if (!is_array($sa)) return null;
        if (empty($sa['client_email']) || empty($sa['private_key'])) return null;
        if (isset($sa['type']) && $sa['type'] !== 'service_account') return null;
        return $sa;
    }
}

if (!function_exists('crm_sheet_http')) {
    /**
     * Small curl wrapper. Returns array(http_code, decoded_body|null, raw_body).
     */
    function crm_sheet_http($method, $url, $headers = array(), $body = null, $timeout = 15)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return array(0, null, $err);
        return array($code, json_decode($raw, true), $raw);
    }
}

if (!function_exists('crm_sheet_access_token')) {
    /**
     * Access token for the config row's service account. Cached in the row
     * (tokens live 1h); mints a new one via the JWT bearer grant when stale.
     * Returns token string or null (last_error is stored on failure).
     */
    function crm_sheet_access_token(&$config)
    {
        $ci = &get_instance();

        if (!empty($config['access_token']) && !empty($config['token_expires_at'])
            && strtotime($config['token_expires_at']) - time() > 120) {
            return $config['access_token'];
        }

        $sa = crm_sheet_parse_sa($config['service_account_json']);
        if (!$sa) {
            crm_sheet_store_error($config, 'Invalid service account JSON.');
            return null;
        }

        $b64 = function ($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); };
        $now = time();
        $header = $b64(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $claims = $b64(json_encode(array(
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        )));
        $signature = '';
        if (!@openssl_sign($header . '.' . $claims, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
            crm_sheet_store_error($config, 'Could not sign JWT: invalid private_key in service account JSON.');
            return null;
        }
        $assertion = $header . '.' . $claims . '.' . $b64($signature);

        list($code, $json, $raw) = crm_sheet_http('POST', 'https://oauth2.googleapis.com/token',
            array('Content-Type: application/x-www-form-urlencoded'),
            http_build_query(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            )));

        if ($code !== 200 || empty($json['access_token'])) {
            crm_sheet_store_error($config, 'Token request failed (HTTP ' . $code . '): ' . substr((string) $raw, 0, 500));
            return null;
        }

        $config['access_token']     = $json['access_token'];
        $config['token_expires_at'] = date('Y-m-d H:i:s', $now + (int) ($json['expires_in'] ?? 3600));
        $ci->db->where('id', $config['id'])->update('crm_sheet_config', array(
            'access_token'     => $config['access_token'],
            'token_expires_at' => $config['token_expires_at'],
            'updated_at'       => date('Y-m-d H:i:s'),
        ));
        return $config['access_token'];
    }
}

if (!function_exists('crm_sheet_store_error')) {
    function crm_sheet_store_error($config, $message)
    {
        $ci = &get_instance();
        log_message('error', 'crm_sheet: ' . $message);
        if (!empty($config['id'])) {
            $ci->db->where('id', $config['id'])->update('crm_sheet_config', array(
                'last_error' => $message,
                'updated_at' => date('Y-m-d H:i:s'),
            ));
        }
    }
}

if (!function_exists('crm_sheet_headers')) {
    function crm_sheet_headers()
    {
        return array('Date', 'Channel', 'Name', 'Phone', 'Email', 'Customer Request / Comment', 'Deal #', 'Lead Status');
    }
}

if (!function_exists('crm_sheet_append_rows')) {
    /**
     * Append raw rows (array of arrays) to the configured tab.
     * Returns true or an error-message string.
     */
    function crm_sheet_append_rows(&$config, $rows)
    {
        $ci = &get_instance();
        $token = crm_sheet_access_token($config);
        if (!$token) return 'auth failed';

        $range = rawurlencode($config['sheet_tab'] !== '' ? $config['sheet_tab'] : 'Sheet1');
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($config['spreadsheet_id'])
             . '/values/' . $range . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        list($code, $json, $raw) = crm_sheet_http('POST', $url,
            array('Authorization: Bearer ' . $token, 'Content-Type: application/json'),
            json_encode(array('values' => array_values($rows))));

        if ($code !== 200) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : substr((string) $raw, 0, 300);
            crm_sheet_store_error($config, 'Append failed (HTTP ' . $code . '): ' . $msg);
            return $msg;
        }

        $ci->db->where('id', $config['id'])->update('crm_sheet_config', array(
            'last_error'     => null,
            'last_synced_at' => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ));
        return true;
    }
}

if (!function_exists('crm_sheet_append_lead')) {
    /**
     * Push one captured lead onto the user's sheet. Fire-and-forget: never
     * throws, never blocks the AI reply path on failure.
     *
     * $lead keys: source, name, phone, email, summary, deal_id, lead_status
     */
    function crm_sheet_append_lead($user_id, $lead)
    {
        try {
            $config = crm_sheet_get_config($user_id);
            if (!$config) return false;
            $row = array(
                date('Y-m-d H:i:s'),
                (string) ($lead['source'] ?? ''),
                (string) ($lead['name'] ?? ''),
                (string) ($lead['phone'] ?? ''),
                (string) ($lead['email'] ?? ''),
                (string) ($lead['summary'] ?? ''),
                isset($lead['deal_id']) ? '#' . (int) $lead['deal_id'] : '',
                (string) ($lead['lead_status'] ?? ''),
            );
            return crm_sheet_append_rows($config, array($row)) === true;
        } catch (Exception $e) {
            log_message('error', 'crm_sheet_append_lead: ' . $e->getMessage());
            return false;
        } catch (Error $e) {
            log_message('error', 'crm_sheet_append_lead: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('crm_sheet_test')) {
    /**
     * Validate the config end-to-end: parse JSON, mint a token, read the
     * spreadsheet metadata, auto-correct the tab name if needed, and write the
     * header row when the sheet is empty. Returns array(ok(bool), message).
     */
    function crm_sheet_test(&$config)
    {
        $ci = &get_instance();

        $sa = crm_sheet_parse_sa($config['service_account_json']);
        if (!$sa) return array(false, 'Invalid service account JSON: it must contain client_email and private_key.');

        $token = crm_sheet_access_token($config);
        if (!$token) {
            $fresh = $ci->db->from('crm_sheet_config')->where('id', $config['id'])->get()->row_array();
            return array(false, 'Google authentication failed: ' . ($fresh['last_error'] ?? 'unknown error'));
        }

        list($code, $json, $raw) = crm_sheet_http('GET',
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($config['spreadsheet_id']) . '?fields=properties.title,sheets.properties.title',
            array('Authorization: Bearer ' . $token));

        if ($code === 403) return array(false, 'Access denied: share the spreadsheet with ' . $sa['client_email'] . ' as Editor.');
        if ($code === 404) return array(false, 'Spreadsheet not found: check the Spreadsheet ID.');
        if ($code !== 200) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : substr((string) $raw, 0, 300);
            return array(false, 'Google Sheets error (HTTP ' . $code . '): ' . $msg);
        }

        // auto-fix tab name against the spreadsheet's real tabs
        $tabs = array();
        foreach (($json['sheets'] ?? array()) as $s) {
            if (!empty($s['properties']['title'])) $tabs[] = $s['properties']['title'];
        }
        if (!empty($tabs) && !in_array($config['sheet_tab'], $tabs, true)) {
            $config['sheet_tab'] = $tabs[0];
            $ci->db->where('id', $config['id'])->update('crm_sheet_config', array('sheet_tab' => $config['sheet_tab']));
        }

        // write header row if the tab is empty
        list($hcode, $hjson) = crm_sheet_http('GET',
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($config['spreadsheet_id'])
            . '/values/' . rawurlencode($config['sheet_tab'] . '!A1:H1'),
            array('Authorization: Bearer ' . $token));
        if ($hcode === 200 && empty($hjson['values'])) {
            crm_sheet_http('PUT',
                'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($config['spreadsheet_id'])
                . '/values/' . rawurlencode($config['sheet_tab'] . '!A1') . '?valueInputOption=RAW',
                array('Authorization: Bearer ' . $token, 'Content-Type: application/json'),
                json_encode(array('values' => array(crm_sheet_headers()))));
        }

        $ci->db->where('id', $config['id'])->update('crm_sheet_config', array('last_error' => null, 'updated_at' => date('Y-m-d H:i:s')));
        return array(true, 'Connected to "' . ($json['properties']['title'] ?? 'spreadsheet') . '" (tab: ' . $config['sheet_tab'] . '). Header row is in place.');
    }
}
