<?php
require_once("Home.php");

/**
 * Unified Inbox (Phase 9.5, brief §13/§14/§46).
 *
 * The primary read+reply surface over the conversation core (conversations, customers,
 * customer_identities — docs/database/conversation-core.md) and the existing
 * livechat_messages store, for every platform (fb, ig, wa, tg, web). It does not replace
 * Messenger_bot/Openwa_bot/Whatsapp_bot/Telegram_bot/Webchat's own send/receive paths, and it
 * does not replace Livechat.php (module 82, fb/ig only) — see docs/product/unified-inbox.md
 * for the coexistence decision.
 *
 * Every method here is tenant-scoped: either through conversation_get()/conversation_transition()
 * (which already accept a $user_id guard and answer "not found" for another tenant) or through
 * inbox_scope_conversations()/inbox_scope_conversation_owner() (application/helpers/inbox_helper.php),
 * which always apply user_id first. No query in this controller touches conversations,
 * livechat_messages or internal_notes without one of those guards.
 */
class Inbox extends Home
{
    public function __construct()
    {
        parent::__construct();
        if ($this->session->userdata('logged_in') != 1) redirect('home/login_page', 'location');
        $this->load->helper(array('entitlement', 'conversation', 'inbox', 'channel_policy', 'channel_adapter', 'secret', 'inbox_realtime', 'team'));
        entitlement_require('inbox');
        // Tenant guard used by every query in this controller (brief: "user_id = $this->uid always").
        $this->uid = $this->session->userdata('real_user_id') ?: $this->session->userdata('user_id');
        // Who is acting (team member id when logged in as a team member, else the account's own id).
        $this->actor_id = (int) $this->session->userdata('user_id');
        $this->actor_name = (string) ($this->session->userdata('username') ?: 'Agent');
    }

    // ------------------------------------------------------------------ shell page

    public function index()
    {
        $data['team_members'] = $this->db->table_exists('team_members')
            ? $this->db->select('id, name')->where('user_id', $this->uid)->order_by('name', 'ASC')->get('team_members')->result_array()
            : array();
        // Task 17 (C2, brief §13): teams this tenant has created, for the "assign to team" /
        // "My team" controls. Empty (not an error) before the migration runs.
        $data['teams_ready'] = team_tables_ready($this->db);
        $data['teams'] = $data['teams_ready']
            ? $this->db->select('id, name')->where('user_id', $this->uid)->order_by('name', 'ASC')->get('teams')->result_array()
            : array();
        $data['platforms'] = conversation_platforms();
        $data['states'] = conversation_states();
        // Realtime: NULL when Pusher is not configured -> the page only polls (see realtime_auth()).
        $data['realtime'] = inbox_realtime_client_config($this, $this->uid);
        // Auto-refresh (polling fallback): config item inbox_poll_enabled === FALSE turns it off entirely.
        $data['poll_seconds'] = $this->config->item('inbox_poll_enabled') === FALSE ? null : inbox_realtime_poll_intervals($this->config->item('inbox_poll_seconds'), $this->config->item('inbox_poll_seconds_realtime'));
        $data['page_title'] = 'Inbox';
        $data['body'] = 'admin/inbox/index';
        $this->_viewcontroller($data);
    }

    // ------------------------------------------------------------------ conversation list

    /** AJAX: paginated, filtered, searched conversation list. Tenant-scoped, one query. */
    public function conversations_data()
    {
        $this->ajax_check();
        header('Content-Type: application/json');

        // Task 17 (C2): both the column and the two team tables must exist before any of the
        // team behaviour switches on — exactly the labels_ready() pattern above.
        $team_col_ready = $this->db->field_exists('assigned_team_id', 'conversations');
        $team_ready = $team_col_ready && team_tables_ready($this->db);

        $filters = array(
            'platform'          => $this->input->post('platform', true),
            'status'            => $this->input->post('status', true),
            'handoff_state'     => $this->input->post('handoff_state', true),
            'assigned_user_id'  => $this->input->post('assigned_user_id', true),
            'assigned_team_id'  => $team_ready ? (int) $this->input->post('assigned_team_id') : 0,
            'unread_only'       => (bool) $this->input->post('unread_only'),
            'label_id'          => $this->labels_ready() ? (int) $this->input->post('label_id') : 0,
            'search'            => $this->input->post('search', true),
        );
        // "My team" (Task 17): only when explicitly requested and teams exist. Resolved here (not
        // inside the pure helper) because it needs a team_memberships lookup for the acting agent.
        if ($team_ready && (bool) $this->input->post('my_team')) {
            $filters['my_team_ids'] = $this->my_team_ids();
        }
        $start = max(0, (int) $this->input->post('start'));
        $length = (int) $this->input->post('length');
        if ($length <= 0 || $length > 100) $length = 20;

        $this->db->select(
            "c.id, c.platform, c.channel_account_id, c.external_thread_id, c.status, c.handoff_state,
             c.assigned_user_id, " . ($team_col_ready ? 'c.assigned_team_id' : '0 AS assigned_team_id') . ",
             c.last_message_at, c.last_inbound_at, c.unread_count, c.message_count,
             cu.id AS customer_id, cu.display_name, cu.phone, cu.email,
             tm.name AS assigned_name, " . ($team_ready ? 't.name' : 'NULL') . " AS assigned_team_name,
             (SELECT lm.message_content FROM livechat_messages lm WHERE lm.conversation_id = c.id ORDER BY lm.id DESC LIMIT 1) AS last_message_preview"
        );
        $this->db->from('conversations c');
        $this->db->join('customers cu', 'cu.id = c.customer_id', 'left');
        $this->db->join('team_members tm', 'tm.id = c.assigned_user_id', 'left');
        // Task 17: the team name shown alongside the individual-agent name, additive only —
        // c.assigned_user_id / tm above are untouched. Only joined once both the column and the
        // teams table exist, so the query still works before the migration runs.
        if ($team_ready) $this->db->join('teams t', 't.id = c.assigned_team_id', 'left');
        inbox_scope_conversations($this->db, $this->uid, $filters);

        $total = $this->db->count_all_results('', false); // keeps WHERE/JOIN, drops SELECT/ORDER/LIMIT for the count
        $this->db->order_by('c.last_message_at', 'DESC');
        $this->db->limit($length, $start);
        $rows = $this->db->get()->result_array();

        foreach ($rows as &$r) {
            $r['handoff_label'] = inbox_handoff_label($r['handoff_state']);
            $r['last_message_preview'] = inbox_message_preview($r['last_message_preview']);
            $r['customer_name'] = $r['display_name'] !== null && trim((string) $r['display_name']) !== ''
                ? $r['display_name'] : ($r['phone'] ?: $r['external_thread_id']);
        }
        unset($r);

        $by_conv = $this->labels_for_conversations(array_column($rows, 'id'));
        foreach ($rows as &$r) $r['labels'] = isset($by_conv[$r['id']]) ? $by_conv[$r['id']] : array();
        unset($r);

        echo json_encode(array('status' => '1', 'total' => $total, 'rows' => $rows));
    }

    // ------------------------------------------------------------------ thread

    /** AJAX: messages for one conversation, newest-cursor paginated. IDOR-checked. */
    public function conversation_thread()
    {
        $this->ajax_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $before_id = (int) $this->input->post('before_id'); // cursor: messages with id < before_id
        $limit = (int) $this->input->post('limit');
        if ($limit <= 0 || $limit > 100) $limit = 30;
        $mark_read = $this->input->post('mark_read') !== null ? (bool) $this->input->post('mark_read') : ($before_id === 0);

        $conv = conversation_get($conversation_id, $this->uid); // tenant guard: NULL for another tenant's id
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        $has_media = $this->media_column_ready();
        $this->db->select('id, sender, platform, agent_name, message_content, conversation_time, message_status, fb_message_id' . ($has_media ? ', media_json' : ''));
        $this->db->from('livechat_messages');
        $this->db->where('conversation_id', $conv['id']); // scoped to THIS conversation only (IDOR guard)
        if ($before_id > 0) $this->db->where('id <', $before_id);
        $this->db->order_by('id', 'DESC');
        $this->db->limit($limit);
        $messages = array_reverse($this->db->get()->result_array());
        // What the thread shows: text plus any media URL already in the stored row (fb/ig
        // webhook bodies, Send API bodies). The stored row is never changed.
        // wa/tg inbound media stored by the webhooks (media_json, migration 2026-10-01) is added.
        foreach ($messages as &$m) {
            $m['render'] = inbox_message_render($m['message_content'], $has_media ? $m['media_json'] : null, base_url());
            unset($m['media_json']);
        }
        unset($m);

        if ($mark_read && $conv['unread_count'] > 0) {
            $this->db->where('id', $conv['id'])->where('user_id', $this->uid)
                ->update('conversations', array('unread_count' => 0, 'updated_at' => date('Y-m-d H:i:s')));
        }

        $this->db->select('id, note, author_name, created_at')->from('internal_notes')
            ->where('conversation_id', $conv['id'])->where('user_id', $this->uid)->order_by('id', 'DESC')->limit(20);
        $notes = $this->db->table_exists('internal_notes') ? $this->db->get()->result_array() : array();

        $labels = $this->labels_for_conversations(array($conv['id']));

        echo json_encode(array('status' => '1', 'conversation' => $conv, 'handoff_label' => inbox_handoff_label($conv['handoff_state']),
            'messages' => $messages, 'notes' => $notes, 'has_more' => count($messages) === $limit,
            'can_send_image' => $before_id === 0 ? $this->can_send_image($conv) : null,
            'ai_draft' => $before_id === 0 ? $this->latest_ai_draft($conv) : null,
            'labels' => isset($labels[$conv['id']]) ? $labels[$conv['id']] : array()));
    }

    // ------------------------------------------------------------------ realtime

    /**
     * POST (Pusher JS private-channel auth): socket_id, channel_name, csrf_token.
     * Authorizes ONLY the logged-in tenant's own inbox channel (inbox_realtime_authorize());
     * any other channel, a bad socket id, a missing CSRF token or realtime being off -> 403.
     * The constructor already requires a session and entitlement module 411.
     */
    public function realtime_auth()
    {
        header('Content-Type: application/json');
        $auth = $this->csrf_token_check(true) === false ? null : inbox_realtime_authorize($this->uid,
            $this->input->post('channel_name', true), $this->input->post('socket_id', true), inbox_realtime_ci_settings($this));
        if ($auth === null) { $this->output->set_status_header(403); echo json_encode(array('error' => 'forbidden')); return; }
        echo json_encode($auth);
    }

    // ------------------------------------------------------------------ reply

    /**
     * AJAX POST: send a human agent reply. ALWAYS goes through channel_policy_decide()
     * first. On deny: no send, no livechat_messages row — the reason and owner-language
     * explanation come back so the UI can offer "save as note" instead of a fabricated send.
     */
    public function reply()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $text = trim((string) $this->input->post('message', true));
        if ($text === '') { echo json_encode(array('status' => '0', 'message' => 'Empty message')); return; }

        $conv = conversation_get($conversation_id, $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        $account = $this->resolve_send_account($conv);
        if ($account === null) {
            echo json_encode(array('status' => '0', 'message' => 'No active channel account found for this conversation')); return;
        }

        // opted_out has no effect on a human-sender decision (channel_policy_helper only
        // gates automation on it), so it is safe to pass false here without another query.
        $decision = inbox_reply_decision($account['policy_key'], $conv['last_inbound_at'], false);
        if (!$decision['allowed']) {
            echo json_encode(array(
                'status' => '0', 'denied' => true, 'reason' => $decision['reason'],
                'explain' => $decision['explain'], 'message' => $decision['explain'],
                'offer_note' => true, 'draft_text' => $text,
            ));
            return;
        }

        $outbound_args = array($account['recipient'], $text, $account['credentials'], $this->uid);
        if ($account['key'] === 'web') {
            $webRow = null;
            $result = channel_adapter('web')->withOutbox(function ($m) use (&$webRow, $conv, $text) {
                $webRow = $this->write_agent_message($conv, $text, '');
            })->sendText(\MonkeyBot\Channels\OutboundMessage::text($account['recipient'], $text, array(), $this->uid));
        } else {
            $result = channel_adapter($account['key'])->sendText(\MonkeyBot\Channels\OutboundMessage::text(...$outbound_args)
                ->withMetadata(array('messaging_type' => $decision['messaging_type'] ?? null, 'tag' => $decision['tag'] ?? null)));
        }

        if (!$result->ok) {
            echo json_encode(array('status' => '0', 'message' => $result->error)); return;
        }

        $message_id = ($account['key'] === 'web') ? ($webRow ?: 0)
            : $this->write_agent_message($conv, $text, (string) ($result->external_message_id ?: ''), $account['fb_page_id']);

        echo json_encode(array('status' => '1', 'message' => 'Sent', 'message_id' => $message_id));
    }

    /** livechat_messages.media_json exists (migration 2026-10-01-inbound-media.sql). */
    private function media_column_ready()
    {
        static $has = null;
        if ($has === null) $has = $this->db->field_exists('media_json', 'livechat_messages');
        return $has;
    }

    /**
     * The latest AI turn recorded for this conversation (ai_conversation_history.conversation_id,
     * migration 2026-09-30c), shown read-only as a suggested reply the agent may copy into the
     * composer. Reads what the AI already produced: no model is called here, nothing is sent.
     * NULL when the column does not exist yet or no AI turn is linked to this conversation.
     */
    private function latest_ai_draft($conv)
    {
        static $has = null;
        if ($has === null) $has = $this->db->table_exists('ai_conversation_history') && $this->db->field_exists('conversation_id', 'ai_conversation_history');
        if (!$has) return null;
        $row = $this->db->select('id, human_message, ai_reply, created_at')->from('ai_conversation_history')
            ->where('user_id', $this->uid)                  // tenant guard
            ->where('conversation_id', (int) $conv['id'])   // this conversation only
            ->order_by('id', 'DESC')->limit(1)->get()->row_array();
        if (empty($row) || trim((string) $row['ai_reply']) === '') return null;
        return array('id' => (int) $row['id'], 'reply' => (string) $row['ai_reply'],
            'answering' => mb_substr((string) $row['human_message'], 0, 300), 'created_at' => $row['created_at']);
    }

    /** Whether this conversation's channel adapter can send an image (capability matrix,
     *  docs/channels/adapters.md). Used to show or hide the attach button. */
    private function can_send_image($conv)
    {
        $account = $this->resolve_send_account($conv);
        if ($account === null) return false;
        $adapter = channel_adapter($account['key']);
        return $adapter !== null && $adapter->supports(\MonkeyBot\Channels\Capability::SEND_IMAGE);
    }

    /**
     * AJAX POST (multipart): send an image reply. Same order of checks as reply(): tenant guard,
     * channel account, then channel_policy_decide(sender='human'). Then the channel must support
     * send_image, and only then is the upload validated (bytes, not the client's name or type:
     * JPEG/PNG, max 5 MB) and stored under upload/inbox/ with a random name and an extension
     * taken from the detected type. The provider fetches the image by its public URL. Only on a
     * successful send is a livechat_messages row written. A failed send deletes the stored file
     * and writes nothing (brief §51, no silent failure).
     */
    public function reply_image()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conv = conversation_get((int) $this->input->post('conversation_id'), $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        $account = $this->resolve_send_account($conv);
        if ($account === null) {
            echo json_encode(array('status' => '0', 'message' => 'No active channel account found for this conversation')); return;
        }
        $adapter = channel_adapter($account['key']);
        if ($adapter === null || !$adapter->supports(\MonkeyBot\Channels\Capability::SEND_IMAGE)) {
            echo json_encode(array('status' => '0', 'unsupported' => true, 'message' => 'Sending images is not supported on this channel yet')); return;
        }

        $decision = inbox_reply_decision($account['policy_key'], $conv['last_inbound_at'], false);
        if (!$decision['allowed']) {
            echo json_encode(array('status' => '0', 'denied' => true, 'reason' => $decision['reason'],
                'explain' => $decision['explain'], 'message' => $decision['explain']));
            return;
        }

        $f = isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null;
        if ($f === null || is_array($f['tmp_name']) || !is_uploaded_file((string) $f['tmp_name'])) {
            echo json_encode(array('status' => '0', 'message' => 'No image was uploaded')); return;
        }
        $check = inbox_image_upload_validate((string) $f['tmp_name'], (int) $f['size'], (int) $f['error']);
        if (!$check['ok']) { echo json_encode(array('status' => '0', 'message' => $check['error'])); return; }

        $rel = inbox_image_storage_name($this->uid, $check['ext'], bin2hex(random_bytes(12)));
        $abs = FCPATH . $rel;
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true) && !is_dir(dirname($abs))) {
            echo json_encode(array('status' => '0', 'message' => 'Could not store the image')); return;
        }
        if (!move_uploaded_file((string) $f['tmp_name'], $abs)) {
            echo json_encode(array('status' => '0', 'message' => 'Could not store the image')); return;
        }
        @chmod($abs, 0644);
        $url = base_url($rel);

        $out = \MonkeyBot\Channels\OutboundMessage::media($account['recipient'], 'image', $url, '', $account['credentials'], $this->uid)
            ->withMetadata(array('messaging_type' => $decision['messaging_type'] ?? null, 'tag' => $decision['tag'] ?? null));
        $result = $adapter->sendMedia($out);
        if (!$result->ok) {
            @unlink($abs);
            echo json_encode(array('status' => '0', 'message' => $result->error)); return;
        }

        $message_id = $this->write_agent_message($conv, inbox_image_message_content($account['recipient'], $url),
            (string) ($result->external_message_id ?: ''), $account['fb_page_id']);
        echo json_encode(array('status' => '1', 'message' => 'Sent', 'message_id' => $message_id, 'url' => $url));
    }

    /** Writes the livechat_messages row for a sent agent reply. The BEFORE INSERT trigger
     *  links it to the existing conversation and updates counters (ADR-001). agent_name
     *  matches Livechat.php's own convention (session username), because livechat_messages.sender
     *  has no 'agent' value — only user/bot/system. */
    private function write_agent_message($conv, $text, $fb_message_id = '', $fb_page_id = '')
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert('livechat_messages', array(
            'user_id'          => $this->uid,
            'subscriber_id'    => $conv['external_thread_id'],
            'page_table_id'    => $conv['channel_account_id'],
            'fb_page_id'       => $fb_page_id,
            'sender'           => 'bot',
            'platform'         => $conv['platform'],
            'agent_name'       => $this->actor_name,
            'message_content'  => $text,
            'conversation_time' => $now,
            'fb_message_id'    => $fb_message_id,
            'message_status'   => 'sent',
            'delivery_status_updated_at' => $now,
            'from_business_suite' => '0',
        ));
        $message_id = (int) $this->db->insert_id();
        inbox_realtime_notify($this, $message_id);
        return $message_id;
    }

    /**
     * Resolves the channel account + adapter registry key + policy-engine key + credentials
     * for a conversation's platform, using conversations.channel_account_id — which is
     * already the exact account row id for fb/ig/wa/tg (ADR-001), so no page/session lookup
     * by string is needed here the way channel_send_helper.php does it.
     */
    private function resolve_send_account($conv)
    {
        $acct_id = (int) $conv['channel_account_id'];
        $recipient = $conv['external_thread_id'];

        if ($conv['platform'] === 'fb' || $conv['platform'] === 'ig') {
            $row = $this->db->select('id, page_id, page_access_token')->from('facebook_rx_fb_page_info')
                ->where('id', $acct_id)->where('user_id', $this->uid)->get()->row_array();
            if (empty($row) || empty($row['page_access_token'])) return null;
            return array('key' => $conv['platform'], 'policy_key' => $conv['platform'], 'recipient' => $recipient,
                'credentials' => array('page_access_token' => $row['page_access_token']), 'fb_page_id' => $row['page_id']);
        }

        if ($conv['platform'] === 'tg') {
            $row = $this->db->select('id, bot_token')->from('telegram_accounts')
                ->where('id', $acct_id)->where('user_id', $this->uid)->get()->row_array();
            if (empty($row) || empty($row['bot_token'])) return null;
            return array('key' => 'tg', 'policy_key' => 'tg', 'recipient' => $recipient,
                'credentials' => array('bot_token' => secret_decrypt($row['bot_token'])), 'fb_page_id' => '');
        }

        if ($conv['platform'] === 'web') {
            return array('key' => 'web', 'policy_key' => 'web', 'recipient' => $recipient, 'credentials' => array(), 'fb_page_id' => '');
        }

        if ($conv['platform'] === 'wa') {
            if ($this->db->table_exists('openwa_accounts')) {
                $row = $this->db->select('id, base_url, api_key, session_id')->from('openwa_accounts')
                    ->where('id', $acct_id)->where('user_id', $this->uid)->where('status', '1')->get()->row_array();
                if (!empty($row)) {
                    return array('key' => 'wa_openwa', 'policy_key' => inbox_platform_to_policy_key('wa', 'wa_openwa'), 'recipient' => $recipient,
                        'credentials' => array('base_url' => $row['base_url'], 'api_key' => secret_decrypt($row['api_key']), 'session_id' => $row['session_id']),
                        'fb_page_id' => '');
                }
            }
            $row = $this->db->select('id, access_token, phone_number_id')->from('whatsapp_accounts')
                ->where('id', $acct_id)->where('user_id', $this->uid)->where('status', '1')->get()->row_array();
            if (empty($row)) return null;
            return array('key' => 'wa_cloud', 'policy_key' => inbox_platform_to_policy_key('wa', 'wa_cloud'), 'recipient' => $recipient,
                'credentials' => array('access_token' => secret_decrypt($row['access_token']), 'phone_number_id' => $row['phone_number_id']),
                'fb_page_id' => '');
        }

        return null;
    }

    // ------------------------------------------------------------------ assignment

    /** AJAX POST: assign (team_member_id > 0) or unassign (0 / empty) a conversation. */
    public function assign()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $team_member_id = (int) $this->input->post('team_member_id');

        $conv = conversation_get($conversation_id, $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        if ($team_member_id > 0) {
            if (!$this->db->table_exists('team_members')) {
                echo json_encode(array('status' => '0', 'message' => 'Team accounts are not enabled for this account')); return;
            }
            $owns = $this->db->where('id', $team_member_id)->where('user_id', $this->uid)->get('team_members')->row_array();
            if (empty($owns)) { echo json_encode(array('status' => '0', 'message' => 'Team member not found')); return; }
        } else {
            $team_member_id = null;
        }

        $this->db->where('id', $conv['id'])->where('user_id', $this->uid)
            ->update('conversations', array('assigned_user_id' => $team_member_id, 'updated_at' => date('Y-m-d H:i:s')));
        $this->load->helper('audit'); audit_record($this->uid, 'inbox.assign', 'conversation', $conv['id'], array('assigned_user_id'=>$conv['assigned_user_id'] ?? null), array('assigned_user_id'=>$team_member_id));
        $this->load->helper('event');
        event_emit($this->uid, 'human.assigned', 'conversation', $conv['id'], array(
            'agent_id' => $team_member_id, 'platform' => $conv['platform'] ?? '', 'team_id' => null,
        ));
        echo json_encode(array('status' => '1', 'message' => 'Assignment updated'));
    }

    // ------------------------------------------------------------------ teams (Task 17, C2, brief §13)

    private function team_not_ready()
    {
        echo json_encode(array('status' => '0', 'message' => 'Teams are not set up on this server yet'));
    }

    /** A team row of this tenant, or NULL (another tenant's id answers "not found"). */
    private function own_team($team_id)
    {
        if ((int) $team_id <= 0 || !team_tables_ready($this->db)) return null;
        team_scope_owner($this->db->from('teams'), $team_id, $this->uid);
        $row = $this->db->limit(1)->get()->row_array();
        return $row ?: null;
    }

    /** team ids (of THIS tenant's teams only) the acting agent belongs to, for "My team". */
    private function my_team_ids()
    {
        if (!team_tables_ready($this->db) || (int) $this->actor_id <= 0) return array();
        $rows = $this->db->select('tm2.team_id, tm2.team_member_id')
            ->from('team_memberships tm2')
            ->join('teams t2', 't2.id = tm2.team_id', 'inner')
            ->where('t2.user_id', $this->uid) // tenant guard: only teams this tenant owns
            ->get()->result_array();
        return team_ids_for_member($rows, $this->actor_id);
    }

    /** AJAX: this tenant's teams, with member count. */
    public function teams_list()
    {
        $this->ajax_check();
        header('Content-Type: application/json');
        if (!team_tables_ready($this->db)) { echo json_encode(array('status' => '1', 'rows' => array(), 'ready' => false)); return; }

        $rows = $this->db->select('t.id, t.name, COUNT(m.id) AS members', false)
            ->from('teams t')
            ->join('team_memberships m', 'm.team_id = t.id', 'left')
            ->where('t.user_id', $this->uid)
            ->group_by('t.id')->order_by('t.name', 'ASC')->get()->result_array();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['members'] = (int) $r['members']; }
        unset($r);
        echo json_encode(array('status' => '1', 'rows' => $rows, 'ready' => true));
    }

    /** AJAX POST: create a team (no team_id) or rename one of this tenant's teams. */
    public function team_save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        if (!team_tables_ready($this->db)) { $this->team_not_ready(); return; }

        $team_id = (int) $this->input->post('team_id');
        $name = team_normalize_name((string) $this->input->post('name'));
        if ($name === null) { echo json_encode(array('status' => '0', 'message' => 'Give the team a name')); return; }

        if ($team_id > 0) {
            if ($this->own_team($team_id) === null) { echo json_encode(array('status' => '0', 'message' => 'Team not found')); return; }
            $this->db->where('id', $team_id)->where('user_id', $this->uid)->update('teams', array('name' => $name));
            echo json_encode(array('status' => '1', 'message' => 'Team updated', 'team' => array('id' => $team_id, 'name' => $name)));
            return;
        }

        $this->db->insert('teams', array('user_id' => $this->uid, 'name' => $name, 'created_at' => date('Y-m-d H:i:s')));
        $new_id = (int) $this->db->insert_id();
        if ($new_id <= 0) { echo json_encode(array('status' => '0', 'message' => 'Could not save the team')); return; }
        $this->load->helper('audit'); audit_record($this->uid, 'team.create', 'team', $new_id, null, array('name' => $name), $this->actor_id, 'user');
        echo json_encode(array('status' => '1', 'message' => 'Team created', 'team' => array('id' => $new_id, 'name' => $name)));
    }

    /** AJAX POST: delete one of this tenant's teams; unassigns it from every conversation first. */
    public function team_delete()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        if (!team_tables_ready($this->db)) { $this->team_not_ready(); return; }

        $team = $this->own_team((int) $this->input->post('team_id'));
        if ($team === null) { echo json_encode(array('status' => '0', 'message' => 'Team not found')); return; }

        $this->db->trans_start();
        if ($this->db->field_exists('assigned_team_id', 'conversations')) {
            $this->db->where('assigned_team_id', $team['id'])->where('user_id', $this->uid)
                ->update('conversations', array('assigned_team_id' => null));
        }
        $this->db->where('team_id', $team['id'])->delete('team_memberships');
        $this->db->where('id', $team['id'])->where('user_id', $this->uid)->delete('teams');
        $this->db->trans_complete();
        if ($this->db->trans_status() === false) { echo json_encode(array('status' => '0', 'message' => 'Could not delete the team')); return; }
        echo json_encode(array('status' => '1', 'message' => 'Team deleted'));
    }

    /** AJAX POST: replace a team's membership with the given team_member ids (tenant-owned only). */
    public function team_membership_set()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        if (!team_tables_ready($this->db)) { $this->team_not_ready(); return; }

        $team = $this->own_team((int) $this->input->post('team_id'));
        if ($team === null) { echo json_encode(array('status' => '0', 'message' => 'Team not found')); return; }

        $member_ids = $this->input->post('team_member_ids');
        $member_ids = is_array($member_ids) ? array_values(array_unique(array_map('intval', $member_ids))) : array();
        if (!empty($member_ids) && $this->db->table_exists('team_members')) {
            $owned = $this->db->select('id')->from('team_members')->where('user_id', $this->uid)
                ->where_in('id', $member_ids)->get()->result_array();
            $member_ids = array_map('intval', array_column($owned, 'id')); // drop ids not owned by this tenant
        } else {
            $member_ids = array();
        }

        $this->db->trans_start();
        $this->db->where('team_id', $team['id'])->delete('team_memberships');
        foreach ($member_ids as $mid) {
            $this->db->insert('team_memberships', array('team_id' => $team['id'], 'team_member_id' => $mid));
        }
        $this->db->trans_complete();
        if ($this->db->trans_status() === false) { echo json_encode(array('status' => '0', 'message' => 'Could not save membership')); return; }
        echo json_encode(array('status' => '1', 'message' => 'Membership updated', 'team_member_ids' => $member_ids));
    }

    /**
     * AJAX POST: assign (team_id > 0) or unassign (0 / empty) a conversation to a TEAM. Additive
     * and independent of assign() above — a conversation can carry an individual assignment
     * (assigned_user_id) and a team assignment (assigned_team_id) at the same time; this never
     * reads or changes assigned_user_id.
     */
    public function assign_team()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        if (!$this->db->field_exists('assigned_team_id', 'conversations') || !team_tables_ready($this->db)) {
            $this->team_not_ready(); return;
        }

        $conversation_id = (int) $this->input->post('conversation_id');
        $team_id = (int) $this->input->post('team_id');

        $conv = conversation_get($conversation_id, $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        if ($team_id > 0) {
            if ($this->own_team($team_id) === null) { echo json_encode(array('status' => '0', 'message' => 'Team not found')); return; }
        } else {
            $team_id = null;
        }

        $before = $this->db->select('assigned_team_id')->from('conversations')->where('id', $conv['id'])->get()->row_array();
        $this->db->where('id', $conv['id'])->where('user_id', $this->uid)
            ->update('conversations', array('assigned_team_id' => $team_id, 'updated_at' => date('Y-m-d H:i:s')));
        $this->load->helper('audit');
        audit_record($this->uid, 'inbox.assign_team', 'conversation', $conv['id'],
            array('assigned_team_id' => $before['assigned_team_id'] ?? null), array('assigned_team_id' => $team_id),
            $this->actor_id, 'user');
        $this->load->helper('event');
        event_emit($this->uid, 'human.assigned', 'conversation', $conv['id'], array(
            'agent_id' => null, 'platform' => $conv['platform'] ?? '', 'team_id' => $team_id,
        ));
        echo json_encode(array('status' => '1', 'message' => 'Team assignment updated'));
    }

    // ------------------------------------------------------------------ handoff state

    /** AJAX POST action=resolve|reopen — walks the shortest valid path to the target state
     *  (inbox_helper.php), auditing every hop via conversation_transition(). */
    public function set_status()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $action = (string) $this->input->post('action', true);
        $conv = conversation_get($conversation_id, $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        if ($action === 'resolve') $path = inbox_resolve_path($conv['handoff_state']);
        elseif ($action === 'reopen') $path = array('AI_ACTIVE');
        else { echo json_encode(array('status' => '0', 'message' => 'Unknown action')); return; }

        $result = $this->walk_transition_path($conv['id'], $path, $action);
        echo json_encode($result);
    }

    /** AJAX POST: pause the bot and put a human on this conversation right now. */
    public function takeover()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $conv = conversation_get($conversation_id, $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        $result = $this->walk_transition_path($conv['id'], inbox_takeover_path($conv['handoff_state']), 'takeover');
        $this->load->helper('audit'); audit_record($this->uid, 'inbox.takeover', 'conversation', $conv['id'], array('handoff_state'=>$conv['handoff_state']), array('handoff_state'=>$result['handoff_state'] ?? null));
        echo json_encode($result);
    }

    /** AJAX POST: hand the conversation back to the AI ("return to bot"). */
    public function return_to_bot()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $conv = conversation_get($conversation_id, $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        $result = $this->walk_transition_path($conv['id'], array('AI_ACTIVE'), 'return_to_bot');
        echo json_encode($result);
    }

    /** Drives conversation_transition() through an ordered list of target states, stopping
     *  (and reporting) on the first hop that fails. Every real hop writes its own audited
     *  conversation_events row (conversation_helper.php) — nothing here writes state directly. */
    private function walk_transition_path($conversation_id, array $path, $note)
    {
        $last = array('ok' => true, 'to' => null);
        foreach ($path as $to) {
            $last = conversation_transition($conversation_id, $to, 'agent', $this->actor_id, $note, $this->uid);
            if (!$last['ok']) {
                return array('status' => '0', 'message' => $last['error'], 'handoff_state' => $last['from']);
            }
        }
        return array('status' => '1', 'message' => 'Updated', 'handoff_state' => $last['to'], 'handoff_label' => inbox_handoff_label($last['to']));
    }

    // ------------------------------------------------------------------ internal notes

    /** AJAX POST: private team note, never sent to the customer. */
    public function note_save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $note = trim((string) $this->input->post('note', true));
        if ($note === '') { echo json_encode(array('status' => '0', 'message' => 'Empty note')); return; }

        $conv = conversation_get($conversation_id, $this->uid);
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        $this->db->insert('internal_notes', array(
            'conversation_id' => $conv['id'], 'user_id' => $this->uid, 'author_id' => $this->actor_id,
            'author_name' => $this->actor_name, 'note' => mb_substr($note, 0, 5000), 'created_at' => date('Y-m-d H:i:s'),
        ));
        echo json_encode(array('status' => '1', 'message' => 'Note saved', 'note_id' => (int) $this->db->insert_id()));
    }

    // ------------------------------------------------------------------ AI summary

    /**
     * AJAX: short, factual AI summary of the thread (brief §13/§51). Generated ON DEMAND from
     * `livechat_messages` (already linked via the Phase 9.5 trigger — ADR-001), NOT from
     * `ai_conversation_history` (that table is keyed per-subscriber, not per-conversation; see
     * docs/product/unified-inbox.md "Deferred"). Cached on `conversations.last_summary` /
     * `last_summary_at` and only regenerated when a newer message has arrived since the cache
     * was written, or when the agent explicitly clicks "Regenerate" (refresh=1).
     */
    public function summarize()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $conversation_id = (int) $this->input->post('conversation_id');
        $refresh = (bool) $this->input->post('refresh');

        $conv = conversation_get($conversation_id, $this->uid); // tenant guard
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }

        $this->load->helper('conversation_summary');
        $needs_new = $refresh || conversation_summary_should_regenerate(
            isset($conv['last_summary_at']) ? $conv['last_summary_at'] : null,
            isset($conv['last_message_at']) ? $conv['last_message_at'] : null
        );

        if (!$needs_new && !empty($conv['last_summary'])) {
            echo json_encode(array(
                'status' => '1', 'summary' => $conv['last_summary'],
                'generated_at' => $conv['last_summary_at'], 'cached' => true,
            ));
            return;
        }

        $ai_cfg = $this->db->from('open_ai_config')->where('user_id', $this->uid)->limit(1)->get()->row_array();
        if (empty($ai_cfg)) {
            // Same wording ai_agent_helper.php's preview already uses for the identical case —
            // never fabricate a summary when there is nothing to generate it with (brief §51).
            echo json_encode(array('status' => '0', 'message' => 'No AI credentials configured'));
            return;
        }

        $this->db->select('id, sender, agent_name, message_content, conversation_time')
            ->from('livechat_messages')->where('conversation_id', $conv['id'])
            ->order_by('id', 'DESC')->limit(50);
        $messages = array_reverse($this->db->get()->result_array());

        // TEST-ONLY seam (see application/config/config.php's conversation_summary_test_fixture
        // docblock): always FALSE in production, flipped only by
        // docker/scripts/staging_summary_billing_tests.sh so the real endpoint can be proven
        // end-to-end without a real AI provider API key.
        $fixture_name = $this->config->item('conversation_summary_test_fixture');
        if (!empty($fixture_name)) {
            $this->load->helper('conversation_summary_test_fixture');
            $fixture_text = conversation_summary_test_fixture($fixture_name);
            $this->load->library('Conversation_summarizer', array('ai_caller' => function ($cfg, $msgs, $overrides) use ($fixture_text) {
                return json_encode(array('choices' => array(array('text' => $fixture_text))));
            }));
        } else {
            $this->load->library('Conversation_summarizer');
        }

        // Remaining-work Task 11 (B3, brief §42): behind config flag 'jobs_enabled' (default
        // OFF), regeneration moves to the async job queue instead of calling the summarizer
        // inline in this request. Flag OFF (today's behaviour, byte-for-byte) falls through
        // to the inline call below.
        //
        // Note the fixture-vs-flag interaction: when 'conversation_summary_test_fixture' is
        // set (staging tests only, never on production — see the docblock above), this block
        // is skipped entirely regardless of 'jobs_enabled', so the fixture always resolves
        // inline and synchronously, exactly like flag-off. The job queue only ever sees real
        // (non-fixture) summary regeneration.
        if (empty($fixture_name) && $this->config->item('jobs_enabled') === true) {
            $this->load->helper('job');

            // Dedupe: a user hammering "regenerate summary" must not pile up N jobs for the
            // same conversation. If one is already queued/running, just report the (possibly
            // stale) cached summary again — the in-flight job will refresh it shortly.
            $already_pending = job_pending_exists($this->uid, 'conversation_summary', array('conversation_id' => $conv['id']));
            $job_id = $already_pending ? -1 : job_enqueue($this->uid, 'conversation_summary', array(
                'conversation_id' => $conv['id'],
                'user_id' => $this->uid,
            ));

            if ($already_pending || $job_id > 0) {
                echo json_encode(array(
                    'status' => '1',
                    'summary' => isset($conv['last_summary']) ? $conv['last_summary'] : null,
                    'generated_at' => isset($conv['last_summary_at']) ? $conv['last_summary_at'] : null,
                    'cached' => true,
                    'queued' => true,
                ));
                return;
            }
            // $job_id === 0: enqueue failed (table missing pre-migration, DB hiccup) — fall
            // through to the inline path below, same as flag-off, so summarize() never just
            // silently does nothing.
        }

        $result = $this->conversation_summarizer->summarize($ai_cfg, $messages, $conv['id']);
        if (empty($result['ok'])) {
            echo json_encode(array('status' => '0', 'message' => $result['message']));
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->where('id', $conv['id'])->where('user_id', $this->uid)
            ->update('conversations', array('last_summary' => $result['summary'], 'last_summary_at' => $now, 'updated_at' => $now));

        echo json_encode(array('status' => '1', 'summary' => $result['summary'], 'generated_at' => $now, 'cached' => false));
    }

    // ------------------------------------------------------------------ customer panel

    /**
     * AJAX: compact Customer 360 panel for the Inbox right rail (Task 16 / brief §16/§13).
     * Reuses the exact same aggregation functions (application/helpers/customer_helper.php)
     * Customers::profile() calls for the full page — this endpoint just asks for a customer
     * by the conversation it is opened from, tenant-scoped through conversation_get() first
     * so another tenant's conversation id can never leak a customer row (IDOR-checked, same
     * as conversation_thread()).
     */
    public function customer_panel()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $this->load->helper('customer');

        $conversation_id = (int) $this->input->post('conversation_id');
        $conv = conversation_get($conversation_id, $this->uid); // tenant guard: NULL for another tenant's id
        if (empty($conv) || empty($conv['customer_id'])) {
            echo json_encode(array('status' => '0', 'message' => 'No customer linked to this conversation')); return;
        }

        $customer = $this->db->select('id, display_name, phone, email, lead_score, source, legacy_subscriber_id, created_at')
            ->from('customers');
        customer_scope_owner($this->db, (int) $conv['customer_id'], $this->uid); // tenant guard again on the customer row itself
        $customer = $customer->get()->row_array();
        if (empty($customer)) {
            echo json_encode(array('status' => '0', 'message' => 'Customer not found')); return;
        }

        $cid = (int) $customer['id'];
        static $ready = array();

        $identities = $this->db->select('platform, external_id, channel_account_id')
            ->from('customer_identities')->where('customer_id', $cid)->where('user_id', $this->uid)
            ->order_by('platform', 'ASC')->get()->result_array();
        $deals = customer_deals_for($this->db, $this->uid, $customer, $ready);
        $other_conversations = array_values(array_filter(
            customer_conversations_for($this->db, $this->uid, $cid, 10),
            function ($c) use ($conversation_id) { return (int) $c['id'] !== $conversation_id; }
        ));

        echo json_encode(array(
            'status' => '1',
            'customer' => array(
                'id' => $cid, 'name' => customer_display_name($customer),
                'phone' => $customer['phone'], 'email' => $customer['email'], 'lead_score' => (int) $customer['lead_score'],
            ),
            'channel_count'      => customer_channel_count($identities),
            'channels'           => array_keys(customer_identities_by_channel($identities)),
            'lead_stage'         => customer_lead_stage_summary($deals),
            'labels'             => customer_labels_for($this->db, $this->uid, $cid, $ready),
            'orders'             => customer_orders_for($this->db, $this->uid, $identities, $ready),
            'other_conversations'=> $other_conversations,
            'profile_url'        => site_url('customers/profile/' . $cid),
        ));
    }

    // ------------------------------------------------------------------ labels

    /** Both label tables exist (migration 2026-09-30-inbox-labels.sql has run). */
    private function labels_ready()
    {
        static $ready = null;
        if ($ready === null) $ready = $this->db->table_exists('inbox_labels') && $this->db->table_exists('conversation_labels');
        return $ready;
    }

    private function labels_not_ready()
    {
        echo json_encode(array('status' => '0', 'message' => 'Labels are not set up on this server yet'));
    }

    /** conversation id => list of {id, name, color}, for this tenant only. One query. */
    private function labels_for_conversations(array $conversation_ids)
    {
        $ids = array_values(array_filter(array_map('intval', $conversation_ids)));
        if (empty($ids) || !$this->labels_ready()) return array();
        $rows = $this->db->select('cl.conversation_id, l.id, l.name, l.color')
            ->from('conversation_labels cl')
            ->join('inbox_labels l', 'l.id = cl.label_id AND l.user_id = cl.user_id', 'inner')
            ->where('cl.user_id', $this->uid)
            ->where_in('cl.conversation_id', $ids)
            ->order_by('l.name', 'ASC')->get()->result_array();
        $out = array();
        foreach ($rows as $r) {
            $out[$r['conversation_id']][] = array('id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color']);
        }
        return $out;
    }

    /** A label row of this tenant, or NULL (another tenant's id answers "not found"). */
    private function own_label($label_id)
    {
        if ((int) $label_id <= 0) return null;
        $this->db->from('inbox_labels');
        inbox_scope_labels($this->db, $this->uid, (int) $label_id);
        $row = $this->db->limit(1)->get()->row_array();
        return $row ?: null;
    }

    /** AJAX: this tenant's labels, with how many conversations carry each. */
    public function labels_list()
    {
        $this->ajax_check();
        header('Content-Type: application/json');
        if (!$this->labels_ready()) { echo json_encode(array('status' => '1', 'rows' => array(), 'ready' => false)); return; }

        $rows = $this->db->select('l.id, l.name, l.color, COUNT(cl.conversation_id) AS conversations', false)
            ->from('inbox_labels l')
            ->join('conversation_labels cl', 'cl.label_id = l.id AND cl.user_id = l.user_id', 'left')
            ->where('l.user_id', $this->uid)
            ->group_by('l.id')->order_by('l.name', 'ASC')->get()->result_array();
        foreach ($rows as &$r) { $r['id'] = (int) $r['id']; $r['conversations'] = (int) $r['conversations']; }
        unset($r);
        echo json_encode(array('status' => '1', 'rows' => $rows, 'ready' => true));
    }

    /** AJAX POST: create a label (no label_id) or rename / recolour one of this tenant's labels. */
    public function label_save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        if (!$this->labels_ready()) { $this->labels_not_ready(); return; }

        $label_id = (int) $this->input->post('label_id');
        $name = inbox_label_normalize_name((string) $this->input->post('name'));
        $color = inbox_label_normalize_color((string) $this->input->post('color'));
        if ($name === null) { echo json_encode(array('status' => '0', 'message' => 'Give the label a name')); return; }

        $dupe = $this->db->select('id')->from('inbox_labels')->where('user_id', $this->uid)->where('name', $name)
            ->limit(1)->get()->row_array();
        if (!empty($dupe) && (int) $dupe['id'] !== $label_id) {
            echo json_encode(array('status' => '0', 'message' => 'A label with this name already exists')); return;
        }

        $now = date('Y-m-d H:i:s');
        if ($label_id > 0) {
            if ($this->own_label($label_id) === null) { echo json_encode(array('status' => '0', 'message' => 'Label not found')); return; }
            $this->db->where('id', $label_id)->where('user_id', $this->uid)
                ->update('inbox_labels', array('name' => $name, 'color' => $color, 'updated_at' => $now));
            echo json_encode(array('status' => '1', 'message' => 'Label updated', 'label' => array('id' => $label_id, 'name' => $name, 'color' => $color)));
            return;
        }

        $count = $this->db->from('inbox_labels')->where('user_id', $this->uid)->count_all_results();
        if ($count >= inbox_label_max_per_tenant()) {
            echo json_encode(array('status' => '0', 'message' => 'You have reached the maximum of ' . inbox_label_max_per_tenant() . ' labels')); return;
        }
        $this->db->insert('inbox_labels', array('user_id' => $this->uid, 'name' => $name, 'color' => $color, 'created_at' => $now, 'updated_at' => $now));
        $new_id = (int) $this->db->insert_id();
        if ($new_id <= 0) { echo json_encode(array('status' => '0', 'message' => 'Could not save the label')); return; }
        echo json_encode(array('status' => '1', 'message' => 'Label created', 'label' => array('id' => $new_id, 'name' => $name, 'color' => $color)));
    }

    /** AJAX POST: delete one of this tenant's labels and take it off every conversation. */
    public function label_delete()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        if (!$this->labels_ready()) { $this->labels_not_ready(); return; }

        $label = $this->own_label((int) $this->input->post('label_id'));
        if ($label === null) { echo json_encode(array('status' => '0', 'message' => 'Label not found')); return; }

        $this->db->trans_start();
        $this->db->where('label_id', $label['id'])->where('user_id', $this->uid)->delete('conversation_labels');
        $this->db->where('id', $label['id'])->where('user_id', $this->uid)->delete('inbox_labels');
        $this->db->trans_complete();
        if ($this->db->trans_status() === false) { echo json_encode(array('status' => '0', 'message' => 'Could not delete the label')); return; }
        echo json_encode(array('status' => '1', 'message' => 'Label deleted'));
    }

    /** AJAX POST: put one of this tenant's labels on one of this tenant's conversations. */
    public function label_attach()
    {
        $this->label_link(true);
    }

    /** AJAX POST: take a label off a conversation. */
    public function label_detach()
    {
        $this->label_link(false);
    }

    private function label_link($attach)
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');
        if (!$this->labels_ready()) { $this->labels_not_ready(); return; }

        $conv = conversation_get((int) $this->input->post('conversation_id'), $this->uid); // tenant guard
        if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }
        $label = $this->own_label((int) $this->input->post('label_id'));             // tenant guard
        if ($label === null) { echo json_encode(array('status' => '0', 'message' => 'Label not found')); return; }

        if ($attach) {
            $this->db->query(
                'INSERT IGNORE INTO conversation_labels (conversation_id, label_id, user_id, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
                array((int) $conv['id'], (int) $label['id'], (int) $this->uid, (int) $this->actor_id, date('Y-m-d H:i:s'))
            );
        } else {
            $this->db->where('conversation_id', (int) $conv['id'])->where('label_id', (int) $label['id'])
                ->where('user_id', $this->uid)->delete('conversation_labels');
        }
        $labels = $this->labels_for_conversations(array($conv['id']));
        echo json_encode(array('status' => '1', 'message' => $attach ? 'Label added' : 'Label removed',
            'labels' => isset($labels[$conv['id']]) ? $labels[$conv['id']] : array()));
    }

    // ------------------------------------------------------------------ canned responses

    /** canned_response.channels exists (migration 2026-09-30b-canned-response-channels.sql). */
    private function canned_has_channels()
    {
        static $has = null;
        if ($has === null) $has = $this->db->field_exists('channels', 'canned_response');
        return $has;
    }

    /**
     * AJAX: canned_response rows for this tenant (the same table Livechat.php / module 82 uses).
     * With conversation_id, only the responses offered on that conversation's channel come back:
     * rows whose `channels` is NULL (every row created before the column existed) show on every
     * channel, exactly as before. Without conversation_id, every row of the tenant comes back.
     * media_type (Livechat's fb/ig page category) is not read or changed here.
     */
    public function canned_responses()
    {
        $this->ajax_check();
        header('Content-Type: application/json');

        $platform = null;
        $conversation_id = (int) $this->input->post('conversation_id');
        if ($conversation_id > 0) {
            $conv = conversation_get($conversation_id, $this->uid); // tenant guard
            if (empty($conv)) { echo json_encode(array('status' => '0', 'message' => 'Conversation not found')); return; }
            $platform = $conv['platform'];
        }

        $has = $this->canned_has_channels();
        $this->db->select($has ? 'id, name, message, channels' : 'id, name, message')->from('canned_response');
        inbox_scope_canned($this->db, $this->uid, $platform, $has);
        $rows = $this->db->order_by('name', 'ASC')->get()->result_array();
        foreach ($rows as &$r) {
            if (!$has) $r['channels'] = null;
            $r['channel_list'] = $r['channels'] === null || $r['channels'] === '' ? array() : explode(',', $r['channels']);
        }
        unset($r);
        echo json_encode(array('status' => '1', 'rows' => $rows, 'platform' => $platform, 'channels_supported' => $has));
    }

    /**
     * AJAX POST: create an inbox canned response, or edit one of this tenant's (name, text and
     * which channels it is offered on). New rows get page_id 0, so Livechat's per-page list
     * (page_id + media_type) never shows them. Editing a Livechat row keeps its page_id and
     * media_type untouched.
     */
    public function canned_save()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $id = (int) $this->input->post('canned_id');
        $name = trim(strip_tags((string) $this->input->post('name', true)));
        $message = trim((string) $this->input->post('message', true));
        if ($name === '' || $message === '') { echo json_encode(array('status' => '0', 'message' => 'Name and message are required')); return; }
        $data = array('name' => mb_substr($name, 0, 255), 'message' => mb_substr($message, 0, 5000));
        if ($this->canned_has_channels()) {
            $data['channels'] = inbox_canned_channels_normalize($this->input->post('channels'));
        }

        if ($id > 0) {
            $own = $this->db->select('id')->from('canned_response')->where('user_id', $this->uid)->where('id', $id)->get()->row_array();
            if (empty($own)) { echo json_encode(array('status' => '0', 'message' => 'Canned response not found')); return; }
            $this->db->where('id', $id)->where('user_id', $this->uid)->update('canned_response', $data);
            echo json_encode(array('status' => '1', 'message' => 'Canned response updated', 'id' => $id));
            return;
        }
        $data['user_id'] = $this->uid;
        $data['page_id'] = 0;
        $this->db->insert('canned_response', $data);
        echo json_encode(array('status' => '1', 'message' => 'Canned response saved', 'id' => (int) $this->db->insert_id()));
    }

    /** AJAX POST: delete one of this tenant's canned responses. */
    public function canned_delete()
    {
        $this->ajax_check();
        $this->csrf_token_check();
        header('Content-Type: application/json');

        $id = (int) $this->input->post('canned_id');
        $this->db->where('id', $id)->where('user_id', $this->uid)->delete('canned_response');
        if ($this->db->affected_rows() < 1) { echo json_encode(array('status' => '0', 'message' => 'Canned response not found')); return; }
        echo json_encode(array('status' => '1', 'message' => 'Canned response deleted'));
    }
}
