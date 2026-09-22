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
        $this->load->helper(array('entitlement', 'conversation', 'inbox', 'channel_policy', 'channel_adapter', 'secret'));
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
        $data['platforms'] = conversation_platforms();
        $data['states'] = conversation_states();
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

        $filters = array(
            'platform'          => $this->input->post('platform', true),
            'status'            => $this->input->post('status', true),
            'handoff_state'     => $this->input->post('handoff_state', true),
            'assigned_user_id'  => $this->input->post('assigned_user_id', true),
            'unread_only'       => (bool) $this->input->post('unread_only'),
            'label_id'          => $this->labels_ready() ? (int) $this->input->post('label_id') : 0,
            'search'            => $this->input->post('search', true),
        );
        $start = max(0, (int) $this->input->post('start'));
        $length = (int) $this->input->post('length');
        if ($length <= 0 || $length > 100) $length = 20;

        $this->db->select(
            "c.id, c.platform, c.channel_account_id, c.external_thread_id, c.status, c.handoff_state,
             c.assigned_user_id, c.last_message_at, c.last_inbound_at, c.unread_count, c.message_count,
             cu.id AS customer_id, cu.display_name, cu.phone, cu.email,
             tm.name AS assigned_name,
             (SELECT lm.message_content FROM livechat_messages lm WHERE lm.conversation_id = c.id ORDER BY lm.id DESC LIMIT 1) AS last_message_preview"
        );
        $this->db->from('conversations c');
        $this->db->join('customers cu', 'cu.id = c.customer_id', 'left');
        $this->db->join('team_members tm', 'tm.id = c.assigned_user_id', 'left');
        inbox_scope_conversations($this->db, $this->uid, $filters);

        $total = $this->db->count_all_results('', false); // keeps WHERE/JOIN, drops SELECT/ORDER/LIMIT for the count
        $this->db->order_by('c.last_message_at', 'DESC');
        $this->db->limit($length, $start);
        $rows = $this->db->get()->result_array();

        foreach ($rows as &$r) {
            $r['handoff_label'] = inbox_handoff_label($r['handoff_state']);
            $r['last_message_preview'] = $r['last_message_preview'] !== null
                ? mb_substr(strip_tags((string) $r['last_message_preview']), 0, 120) : '';
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

        $this->db->select('id, sender, platform, agent_name, message_content, conversation_time, message_status, fb_message_id');
        $this->db->from('livechat_messages');
        $this->db->where('conversation_id', $conv['id']); // scoped to THIS conversation only (IDOR guard)
        if ($before_id > 0) $this->db->where('id <', $before_id);
        $this->db->order_by('id', 'DESC');
        $this->db->limit($limit);
        $messages = array_reverse($this->db->get()->result_array());

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
            'labels' => isset($labels[$conv['id']]) ? $labels[$conv['id']] : array()));
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
            $result = channel_adapter($account['key'])->sendText(\MonkeyBot\Channels\OutboundMessage::text(...$outbound_args));
        }

        if (!$result->ok) {
            echo json_encode(array('status' => '0', 'message' => $result->error)); return;
        }

        $message_id = ($account['key'] === 'web') ? ($webRow ?: 0)
            : $this->write_agent_message($conv, $text, (string) ($result->external_message_id ?: ''), $account['fb_page_id']);

        echo json_encode(array('status' => '1', 'message' => 'Sent', 'message_id' => $message_id));
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
        return (int) $this->db->insert_id();
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
        echo json_encode(array('status' => '1', 'message' => 'Assignment updated'));
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

        $result = $this->conversation_summarizer->summarize($ai_cfg, $messages);
        if (empty($result['ok'])) {
            echo json_encode(array('status' => '0', 'message' => $result['message']));
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->where('id', $conv['id'])->where('user_id', $this->uid)
            ->update('conversations', array('last_summary' => $result['summary'], 'last_summary_at' => $now, 'updated_at' => $now));

        echo json_encode(array('status' => '1', 'summary' => $result['summary'], 'generated_at' => $now, 'cached' => false));
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
