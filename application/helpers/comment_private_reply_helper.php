<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Comment private replies -> Unified Inbox.
 *
 * When Comment_automation (Facebook) or Instagram_reply (Instagram) answers a comment, it can
 * also send the commenter a private message through the Send API with
 * recipient.comment_id. Until now that message was never written to livechat_messages, so the
 * conversation core (ADR-001) never saw it and the inbox did not show that the business had
 * already messaged the customer privately.
 *
 * How it works:
 *  1. Capture, at send time. Fb_rx_login::send_private_reply() and the send_reply() overrides in
 *     Comment_automation / Instagram_reply hand every (payload, Graph response) pair to
 *     cpr_buffer_capture(). Only a comment-addressed send that Graph accepted (no `error`, a
 *     message id) is kept, in a per-request buffer. Nothing is written at this point.
 *  2. Flush, after the campaign report row is written. cpr_flush() drains the buffer and writes
 *     one livechat_messages row per sent message, shaped like every other fb/ig bot send
 *     (sender 'bot', platform fb/ig, page_table_id, fb_page_id, a Send API body as
 *     message_content, fb_message_id = the Graph message id). The existing BEFORE INSERT trigger
 *     (2026-09-23b-conversation-link-trigger.sql) then creates or updates the conversation.
 *  3. Optional marker: the first new row for a comment also gets an internal_notes entry (the
 *     inbox's existing private-note mechanism) that records the customer's public comment and the
 *     public reply. Notes are escaped by the inbox UI and never shown in Livechat.php, which
 *     prints `system` rows as raw HTML; that is why the comment text does not go there.
 *
 * Who is the customer (subscriber_id)? A comment webhook carries the commenter's app-scoped
 * id, not the PSID/IGSID that later Messenger/Instagram DMs use. In order:
 *  a. `recipient_id` from the Send API response, when Graph returns it;
 *  b. the PSID the caller already resolved: Comment_automation and Instagram_reply look the
 *     first private-reply message up (Fb_rx_login::get_private_reply_message_id_info, `to[0].id`)
 *     to create the messenger_bot_subscriber row, and that value is passed in;
 *  c. the same lookup done here, at most CPR_MAX_LOOKUPS times per flush, for the paths that
 *     never did one (Instagram AI replies, mentions, offensive-comment deletes).
 * With no PSID the message is skipped (logged at debug level): writing it under the comment
 * author's id would create a conversation that no later DM could ever join.
 *
 * Safety: nothing here may break or slow the reply path. Every public entry point catches
 * Throwable, db_debug is switched off around the writes (CI would otherwise show_error()+exit on
 * a SQL error), and the reply has already been sent and reported before cpr_flush() runs.
 * Idempotent: a row whose fb_message_id already exists is not written again, so a webhook retry
 * or a second flush of the same send cannot duplicate it (Meta also refuses a second private
 * reply to the same comment, so a retried webhook does not produce a new message id).
 */

if (!defined('CPR_MAX_LOOKUPS')) define('CPR_MAX_LOOKUPS', 3);
if (!defined('CPR_MAX_BUFFER')) define('CPR_MAX_BUFFER', 50);
if (!defined('CPR_AGENT_NAME')) define('CPR_AGENT_NAME', 'Comment auto-reply');

if (!function_exists('cpr_capture')) {
    /**
     * Pure. A successful comment private reply as a capture record, or null.
     *
     * @param string|array $payload the Send API body as posted (JSON string or array)
     * @param mixed        $result  the decoded Graph response
     * @return array{comment_id:string,message_id:string,recipient_id:string,message:array}|null
     */
    function cpr_capture($payload, $result)
    {
        if (is_string($payload)) $payload = json_decode($payload, true);
        if (!is_array($payload) || !is_array($result) || isset($result['error'])) return null;

        $cid = isset($payload['recipient']['comment_id']) ? $payload['recipient']['comment_id'] : null;
        if (!is_scalar($cid) || trim((string) $cid) === '') return null;

        $mid = isset($result['message_id']) ? $result['message_id'] : (isset($result['id']) ? $result['id'] : null);
        if (!is_scalar($mid) || trim((string) $mid) === '') return null;

        $message = isset($payload['message']) ? $payload['message'] : null;
        if (!is_array($message) || empty($message)) return null;

        $rid = isset($result['recipient_id']) && is_scalar($result['recipient_id']) ? trim((string) $result['recipient_id']) : '';

        return array(
            'comment_id'   => (string) $cid,
            'message_id'   => trim((string) $mid),
            'recipient_id' => $rid,
            'message'      => $message,
        );
    }
}

if (!function_exists('cpr_buffer')) {
    /** Per-request buffer. cpr_buffer('add', $cap) appends; cpr_buffer('take') returns and clears. */
    function cpr_buffer($op, $cap = null)
    {
        static $buf = array();
        if ($op === 'add') {
            if (is_array($cap) && count($buf) < CPR_MAX_BUFFER) $buf[] = $cap;
            return count($buf);
        }
        if ($op === 'take') { $out = $buf; $buf = array(); return $out; }
        return $buf;
    }
}

if (!function_exists('cpr_buffer_capture')) {
    /** Send-time hook. Never throws; returns true when the send was captured. */
    function cpr_buffer_capture($payload, $result)
    {
        try {
            $cap = cpr_capture($payload, $result);
            if ($cap === null) return false;
            cpr_buffer('add', $cap);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cpr_resolve_psids')) {
    /**
     * Pure apart from $lookup. Pairs every capture with the customer's PSID/IGSID (see the file
     * header for the order), dropping duplicates by message id and captures with no PSID.
     *
     * @param array         $caps              capture records (cpr_capture)
     * @param string        $fallback_comment_id the comment the caller resolved $fallback_psid for
     * @param string        $fallback_psid     PSID the caller already looked up ('' if none)
     * @param callable|null $lookup            function(string $message_id): string PSID, may throw
     * @return array<int,array{cap:array,psid:string}>
     */
    function cpr_resolve_psids(array $caps, $fallback_comment_id, $fallback_psid, $lookup = null, $max_lookups = CPR_MAX_LOOKUPS)
    {
        $groups = array(); $seen = array();
        foreach ($caps as $c) {
            if (!is_array($c) || !isset($c['comment_id'], $c['message_id'])) continue;
            if (isset($seen[$c['message_id']])) continue;
            $seen[$c['message_id']] = true;
            $groups[$c['comment_id']][] = $c;
        }

        $out = array(); $lookups = 0;
        $fallback_psid = trim((string) $fallback_psid);
        foreach ($groups as $cid => $list) {
            $psid = '';
            foreach ($list as $c) { if ($c['recipient_id'] !== '') { $psid = $c['recipient_id']; break; } }
            if ($psid === '' && $fallback_psid !== '' && (string) $cid === (string) $fallback_comment_id) $psid = $fallback_psid;
            if ($psid === '' && is_callable($lookup) && $lookups < $max_lookups) {
                $lookups++;
                try { $psid = trim((string) call_user_func($lookup, $list[0]['message_id'])); } catch (\Throwable $e) { $psid = ''; }
            }
            if ($psid === '') {
                if (function_exists('log_message')) log_message('debug', 'cpr: no PSID for comment ' . $cid . ', private reply not logged to the inbox');
                continue;
            }
            foreach ($list as $c) $out[] = array('cap' => $c, 'psid' => $psid);
        }
        return $out;
    }
}

if (!function_exists('cpr_livechat_row')) {
    /**
     * Pure. The livechat_messages row for one sent private reply. message_content is a Send API
     * body addressed to the PSID, the format Messenger_bot, Livechat.php and Inbox::reply_image
     * already store for fb/ig bot sends (inbox_message_render / display_sent_message read it).
     */
    function cpr_livechat_row(array $cap, $psid, array $ctx, $now)
    {
        $body = json_encode(array('recipient' => array('id' => (string) $psid), 'message' => $cap['message']),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return array(
            'user_id'           => (int) (isset($ctx['user_id']) ? $ctx['user_id'] : 0) ?: null,
            'subscriber_id'     => (string) $psid,
            'page_table_id'     => (int) (isset($ctx['page_table_id']) ? $ctx['page_table_id'] : 0),
            'fb_page_id'        => (string) (isset($ctx['fb_page_id']) ? $ctx['fb_page_id'] : ''),
            'sender'            => 'bot',
            'platform'          => (isset($ctx['platform']) && $ctx['platform'] === 'ig') ? 'ig' : 'fb',
            'agent_name'        => CPR_AGENT_NAME,
            'message_content'   => $body === false ? '' : $body,
            'conversation_time' => $now,
            'fb_message_id'     => $cap['message_id'],
            'message_status'    => 'sent',
            'delivery_status_updated_at' => $now,
            'from_business_suite' => '0',
        );
    }
}

if (!function_exists('cpr_note_text')) {
    /**
     * Pure. The internal note that records the public side of the exchange. $report is the
     * campaign's report row (facebook_ex_autoreply_report / instagram_autoreply_report shape).
     */
    function cpr_note_text($platform, $comment_id, array $report)
    {
        $clip = function ($s, $n) {
            $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
            return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
        };
        $net = $platform === 'ig' ? 'Instagram' : 'Facebook';
        $comment = $clip(isset($report['comment_text']) ? $report['comment_text'] : '', 500);
        $public  = $clip(isset($report['comment_reply_text']) ? $report['comment_reply_text'] : '', 500);
        $status  = strtolower(trim((string) (isset($report['reply_status_comment']) ? $report['reply_status_comment'] : '')));

        $lines = array('Customer commented on a ' . $net . ' post (comment ' . $comment_id . ').');
        if ($comment !== '') $lines[] = 'Comment: "' . $comment . '"';
        if ($status === 'comment deleted') {
            $lines[] = 'The comment was deleted by an offensive-word rule.';
        } elseif ($public !== '' && ($status === 'success' || $status === 'comment hidden')) {
            $lines[] = 'Public reply: "' . $public . '"';
            if ($status === 'comment hidden') $lines[] = 'The comment was hidden after the reply.';
        } else {
            $lines[] = 'No public reply was posted.';
        }
        $lines[] = 'The private message in this thread was sent automatically by the comment auto-reply campaign.';
        return implode("\n", $lines);
    }
}

if (!function_exists('cpr_write')) {
    /**
     * Writes one bot row unless its fb_message_id is already stored. Returns
     * array{id:int, conversation_id:int} for a new row, null when it already existed.
     * Throws on DB failure (cpr_flush catches).
     */
    function cpr_write($db, array $row)
    {
        $q = $db->select('id')->from('livechat_messages')->where('fb_message_id', $row['fb_message_id'])->limit(1)->get();
        if ($q === false) throw new \RuntimeException('dedupe query failed');
        if ($q->row_array()) return null;

        if (!$db->insert('livechat_messages', $row)) throw new \RuntimeException('insert failed');
        $id = (int) $db->insert_id();
        // realtime Unified Inbox (ids only, fire-and-forget, never throws into the reply path)
        try {
            if (function_exists('get_instance') && ($CI =& get_instance()) && isset($CI->load)) {
                $CI->load->helper('inbox_realtime');
                if (function_exists('inbox_realtime_notify')) inbox_realtime_notify($CI, $id);
            }
        } catch (\Throwable $e) { /* realtime is best-effort */ }

        $conv = 0;
        $q = $db->select('conversation_id')->from('livechat_messages')->where('id', $id)->limit(1)->get();
        if ($q !== false) { $r = $q->row_array(); $conv = (int) (isset($r['conversation_id']) ? $r['conversation_id'] : 0); }
        return array('id' => $id, 'conversation_id' => $conv);
    }
}

if (!function_exists('cpr_flush')) {
    /**
     * Drains the buffer into livechat_messages (+ one internal note per comment). Never throws.
     *
     * $ctx: platform ('fb'|'ig'), user_id, page_table_id, fb_page_id (Facebook page id, also for
     * Instagram, as Messenger_bot stores it), token (page access token, for the PSID lookup),
     * report (the campaign report row, for the note), fallback_psid (PSID the caller resolved
     * for report.comment_id, '' if none).
     *
     * @return int rows written
     */
    function cpr_flush($ci, array $ctx)
    {
        $caps = cpr_buffer('take');
        if (empty($caps)) return 0;
        $written = 0;
        $db = null; $prev_debug = null;
        try {
            $report = isset($ctx['report']) && is_array($ctx['report']) ? $ctx['report'] : array();
            $report_cid = isset($report['comment_id']) ? (string) $report['comment_id'] : '';
            $token = isset($ctx['token']) ? (string) $ctx['token'] : '';

            $lookup = null;
            if ($token !== '' && isset($ci->fb_rx_login) && is_object($ci->fb_rx_login) && method_exists($ci->fb_rx_login, 'get_private_reply_message_id_info')) {
                $lookup = function ($mid) use ($ci, $token) {
                    $info = $ci->fb_rx_login->get_private_reply_message_id_info($mid, $token);
                    return isset($info['to'][0]['id']) ? $info['to'][0]['id'] : '';
                };
            }
            $pairs = cpr_resolve_psids($caps, $report_cid, isset($ctx['fallback_psid']) ? $ctx['fallback_psid'] : '', $lookup);
            if (empty($pairs)) return 0;

            if (!is_object($ci) || !isset($ci->db) || !is_object($ci->db)) return 0;
            $db = $ci->db;
            $prev_debug = $db->db_debug;
            $db->db_debug = FALSE;
            $now = date('Y-m-d H:i:s');
            $platform = (isset($ctx['platform']) && $ctx['platform'] === 'ig') ? 'ig' : 'fb';
            $noted = array();

            foreach ($pairs as $p) {
                try {
                    $res = cpr_write($db, cpr_livechat_row($p['cap'], $p['psid'], $ctx, $now));
                } catch (\Throwable $e) {
                    if (method_exists($db, 'reset_query')) $db->reset_query();
                    if (function_exists('log_message')) log_message('error', 'cpr: livechat write failed for ' . $p['cap']['message_id'] . ': ' . $e->getMessage());
                    continue;
                }
                if ($res === null) continue;
                $written++;

                $cid = $p['cap']['comment_id'];
                if (isset($noted[$cid]) || $cid !== $report_cid || $res['conversation_id'] <= 0 || empty($ctx['user_id'])) continue;
                $noted[$cid] = true;
                try {
                    $db->insert('internal_notes', array(
                        'conversation_id' => $res['conversation_id'],
                        'user_id'         => (int) $ctx['user_id'],
                        'author_id'       => 0,
                        'author_name'     => CPR_AGENT_NAME,
                        'note'            => cpr_note_text($platform, $cid, $report),
                        'created_at'      => $now,
                    ));
                } catch (\Throwable $e) {
                    if (method_exists($db, 'reset_query')) $db->reset_query();
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('error', 'cpr: flush failed: ' . $e->getMessage());
        } finally {
            if ($db !== null && $prev_debug !== null) $db->db_debug = $prev_debug;
        }
        return $written;
    }
}
