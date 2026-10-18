<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Instagram follow gate for comment/mention private replies.
 *
 *   comment -> private reply = gate text + one postback button (payload IGFG_<pending id>)
 *           -> real reply(ies) parked in ig_follow_gate_pending
 *   tap     -> Messenger_bot webhook -> is_user_follow_business?
 *              yes / unknown -> deliver parked messages to the user's IGSID (fail open: a lead
 *                               is never stuck because Meta did not return the field)
 *              no            -> retry text + same button
 *
 * Every DB/API error fails OPEN to the old behaviour (reply sent without a gate).
 */

if (!defined('IGFG_PAYLOAD_PREFIX')) define('IGFG_PAYLOAD_PREFIX', 'IGFG_');
if (!defined('IGFG_MAX_ATTEMPTS')) define('IGFG_MAX_ATTEMPTS', 5);
if (!defined('IGFG_TTL_DAYS')) define('IGFG_TTL_DAYS', 7);

if (!function_exists('igfg_defaults')) {
    function igfg_defaults()
    {
        return array(
            'text'   => "أهلاً 👋 عشان يوصلك الرد، تابع الحساب الأول وبعدين اضغط الزرار اللي تحت.",
            'button' => "تابعت ✅",
            'retry'  => "لسه مش ظاهر عندنا إنك متابع 🙏 تابع الحساب واضغط الزرار تاني.",
        );
    }
}

if (!function_exists('igfg_settings')) {
    /** Gate settings for a campaign, or NULL when off / unavailable (column missing => NULL). */
    function igfg_settings($db, $autoreply_table_id)
    {
        $prev = isset($db->db_debug) ? $db->db_debug : null;
        if ($prev !== null) $db->db_debug = false;
        try {
            $q = $db->query('SELECT id, page_info_table_id, follow_gate, follow_gate_text, follow_gate_button, follow_gate_retry_text FROM instagram_reply_autoreply WHERE id = ? LIMIT 1', array((int) $autoreply_table_id));
            if (!$q) return null;
            $r = $q->row_array();
            if (empty($r) || (int) $r['follow_gate'] !== 1) return null;
            $d = igfg_defaults();
            return array(
                'campaign_id'   => (int) $r['id'],
                'page_table_id' => (int) $r['page_info_table_id'],
                'text'   => trim((string) $r['follow_gate_text']) !== '' ? (string) $r['follow_gate_text'] : $d['text'],
                'button' => trim((string) $r['follow_gate_button']) !== '' ? (string) $r['follow_gate_button'] : $d['button'],
                'retry'  => trim((string) $r['follow_gate_retry_text']) !== '' ? (string) $r['follow_gate_retry_text'] : $d['retry'],
            );
        } catch (\Throwable $e) {
            return null;
        } finally {
            if ($prev !== null) $db->db_debug = $prev;
        }
    }
}

if (!function_exists('igfg_button_message')) {
    /** Send API body: text + one postback button, to either a comment (private reply) or an IGSID. */
    function igfg_button_message(array $recipient, $text, $button, $pending_id)
    {
        $button = function_exists('mb_substr') ? mb_substr((string) $button, 0, 20, 'UTF-8') : substr((string) $button, 0, 20);
        $text = function_exists('mb_substr') ? mb_substr((string) $text, 0, 640, 'UTF-8') : substr((string) $text, 0, 640);
        return json_encode(array(
            'recipient' => $recipient,
            'message' => array('attachment' => array('type' => 'template', 'payload' => array(
                'template_type' => 'button',
                'text' => $text,
                'buttons' => array(array('type' => 'postback', 'title' => $button, 'payload' => IGFG_PAYLOAD_PREFIX . (int) $pending_id)),
            ))),
        ), JSON_UNESCAPED_UNICODE);
    }
}

if (!class_exists('Igfg_gate')) {
    /**
     * One per webhook request. intercept() is called with every outgoing Send API body:
     *   - the first one addressed to a comment is parked and replaced by the gate message;
     *   - later ones in the same request are parked too (returned NULL = do not send).
     */
    class Igfg_gate
    {
        public $settings; public $db; public $pending_id = 0; public $parked = array();
        public function __construct($db, array $settings) { $this->db = $db; $this->settings = $settings; }

        /** @return string|null  body to send instead, or NULL to send nothing. */
        public function intercept($body)
        {
            $msg = json_decode((string) $body, true);
            if (!is_array($msg) || !isset($msg['message'])) return $body;

            if ($this->pending_id > 0) {             // already gated in this request: park, send nothing
                $this->parked[] = $msg;
                return $this->save() ? null : $body;
            }
            if (!isset($msg['recipient']['comment_id']) || (string) $msg['recipient']['comment_id'] === '') return $body;

            $this->parked[] = $msg;
            $prev = isset($this->db->db_debug) ? $this->db->db_debug : null;
            if ($prev !== null) $this->db->db_debug = false;
            try {
                $ok = $this->db->query('INSERT INTO ig_follow_gate_pending (autoreply_table_id, page_table_id, comment_id, messages_json) VALUES (?, ?, ?, ?)',
                    array($this->settings['campaign_id'], $this->settings['page_table_id'], (string) $msg['recipient']['comment_id'], json_encode($this->parked, JSON_UNESCAPED_UNICODE)));
                if (!$ok) { $this->parked = array(); return $body; }
                $this->pending_id = (int) $this->db->insert_id();
                if ($this->pending_id <= 0) { $this->parked = array(); return $body; }
            } catch (\Throwable $e) {
                $this->parked = array();
                return $body;                           // fail open: no gate
            } finally {
                if ($prev !== null) $this->db->db_debug = $prev;
            }
            return igfg_button_message(array('comment_id' => (string) $msg['recipient']['comment_id']), $this->settings['text'], $this->settings['button'], $this->pending_id);
        }

        private function save()
        {
            try {
                return (bool) $this->db->query('UPDATE ig_follow_gate_pending SET messages_json = ? WHERE id = ?', array(json_encode($this->parked, JSON_UNESCAPED_UNICODE), $this->pending_id));
            } catch (\Throwable $e) { return false; }
        }
    }
}

if (!function_exists('igfg_parse_payload')) {
    /** "IGFG_123" -> 123, anything else -> 0 */
    function igfg_parse_payload($payload)
    {
        $payload = (string) $payload;
        if (strpos($payload, IGFG_PAYLOAD_PREFIX) !== 0) return 0;
        $id = substr($payload, strlen(IGFG_PAYLOAD_PREFIX));
        return ctype_digit($id) ? (int) $id : 0;
    }
}

if (!function_exists('igfg_follow_status')) {
    /**
     * Decode the User Profile API response for fields=is_user_follow_business.
     * @return bool|null  TRUE follower, FALSE not, NULL unknown (error / field absent)
     */
    function igfg_follow_status($api_response)
    {
        if (!is_array($api_response) || isset($api_response['error'])) return null;
        if (!array_key_exists('is_user_follow_business', $api_response)) return null;
        return (bool) $api_response['is_user_follow_business'];
    }
}

if (!function_exists('igfg_decide')) {
    /**
     * What to do on a tap.  @return string  deliver | retry | ignore
     *   ignore: unknown/finished/expired row, or too many taps (spam guard)
     */
    function igfg_decide($row, $follow_status, $now_ts = null)
    {
        if (empty($row) || !in_array($row['status'], array('pending', 'waiting'), true)) return 'ignore';
        $now_ts = $now_ts === null ? time() : $now_ts;
        if (strtotime($row['created_at']) < $now_ts - IGFG_TTL_DAYS * 86400) return 'ignore';
        if ($follow_status === false) return ((int) $row['attempts'] >= IGFG_MAX_ATTEMPTS) ? 'ignore' : 'retry';
        return 'deliver';                               // true, or null = fail open
    }
}

if (!function_exists('igfg_readdress')) {
    /** Parked messages -> Send API bodies addressed to the user who tapped (24h window is open now). */
    function igfg_readdress($messages_json, $igsid)
    {
        $list = json_decode((string) $messages_json, true);
        if (!is_array($list)) return array();
        $out = array();
        foreach ($list as $m) {
            if (!is_array($m) || !isset($m['message'])) continue;
            $m['recipient'] = array('id' => (string) $igsid);
            unset($m['tag']);                           // inside the 24h window no tag is needed
            $m['messaging_type'] = 'RESPONSE';
            $out[] = json_encode($m, JSON_UNESCAPED_UNICODE);
        }
        return $out;
    }
}
