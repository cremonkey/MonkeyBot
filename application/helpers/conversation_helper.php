<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Unified conversation core (Phase 9.5, ADR-001, brief sections 12, 14, 16, 47).
 *
 *  - conversation_find_or_create()   one conversation per (tenant, platform, channel account,
 *                                    external thread), with one customer and identity
 *  - conversation_transition()       the section 14 handoff state machine, audited in
 *                                    conversation_events, mirrored to bot_paused_until
 *  - conversation_is_bot_allowed()   today's bot_paused_until answer, in one place
 *  - conversation_ai_history_link()  conversation_id for a new ai_conversation_history row
 *
 * New livechat_messages rows are linked by the trigger trg_livechat_messages_link_conversation
 * (migration 2026-09-23b). History is linked by: php index.php cron_hub backfill_conversations
 *
 * Compatibility (brief section 47): until every reader moves to handoff_state,
 * messenger_bot_subscriber.bot_paused_until stays the switch the bot obeys. Transitions
 * write it the same way the existing writers do (PHP date(), +6 hours).
 */

if (!defined('CONVERSATION_PAUSE_WINDOW')) define('CONVERSATION_PAUSE_WINDOW', '+6 hours');

if (!function_exists('conversation_platforms')) {
    function conversation_platforms()
    {
        return array('fb', 'ig', 'wa', 'tg', 'web');
    }
}

if (!function_exists('conversation_states')) {
    function conversation_states()
    {
        return array('AI_ACTIVE', 'WAITING_FOR_HUMAN', 'HUMAN_ACTIVE', 'AI_ASSIST', 'RESOLVED');
    }
}

if (!function_exists('conversation_actor_types')) {
    function conversation_actor_types()
    {
        return array('system', 'ai', 'agent', 'customer');
    }
}

if (!function_exists('conversation_transition_map')) {
    /** Allowed from => to edges (brief section 14). "Return to bot" (any state => AI_ACTIVE
     *  by an agent) is handled separately in conversation_transition_allowed(). */
    function conversation_transition_map()
    {
        return array(
            'AI_ACTIVE'         => array('WAITING_FOR_HUMAN'),
            'WAITING_FOR_HUMAN' => array('HUMAN_ACTIVE'),
            'HUMAN_ACTIVE'      => array('AI_ASSIST', 'RESOLVED'),
            'AI_ASSIST'         => array('HUMAN_ACTIVE'),
            'RESOLVED'          => array('AI_ACTIVE'),
        );
    }
}

if (!function_exists('conversation_transition_allowed')) {
    function conversation_transition_allowed($from, $to, $actor_type)
    {
        if (!in_array($to, conversation_states(), true)) return false;
        if ($to === 'AI_ACTIVE' && $actor_type === 'agent') return true; // agent "return to bot"
        $map = conversation_transition_map();
        return isset($map[$from]) && in_array($to, $map[$from], true);
    }
}

if (!function_exists('conversation_pause_value_for_state')) {
    /** bot_paused_until value a state implies: a datetime string (paused), NULL (clear).
     *  AI_ASSIST keeps the pause: a human owns the thread and the AI only drafts for them. */
    function conversation_pause_value_for_state($state)
    {
        if (in_array($state, array('WAITING_FOR_HUMAN', 'HUMAN_ACTIVE', 'AI_ASSIST'), true)) {
            return date('Y-m-d H:i:s', strtotime(CONVERSATION_PAUSE_WINDOW));
        }
        return null; // AI_ACTIVE, RESOLVED
    }
}

if (!function_exists('conversation_db')) {
    function conversation_db()
    {
        $ci =& get_instance();
        return $ci->db;
    }
}

if (!function_exists('conversation_resolve_account')) {
    /**
     * The channel account id a livechat row belongs to. Same rule as the link trigger:
     * fb/ig rows written by channel_log_message() carry page_table_id 0 plus the Facebook page
     * id, so resolve that to the page row of this tenant.
     */
    function conversation_resolve_account($user_id, $platform, $page_table_id, $fb_page_id = '')
    {
        $acct = (int) $page_table_id;
        if ($acct === 0 && in_array($platform, array('fb', 'ig'), true) && (string) $fb_page_id !== '') {
            $row = conversation_db()->query(
                'SELECT id FROM facebook_rx_fb_page_info WHERE user_id = ? AND page_id = ? ORDER BY id LIMIT 1',
                array((int) $user_id, (string) $fb_page_id)
            );
            $r = $row ? $row->row_array() : null;
            if (!empty($r['id'])) $acct = (int) $r['id'];
        }
        return $acct;
    }
}

if (!function_exists('conversation_find')) {
    function conversation_find($user_id, $platform, $channel_account_id, $external_thread_id)
    {
        $q = conversation_db()->query(
            'SELECT * FROM conversations WHERE user_id = ? AND platform = ? AND channel_account_id = ? AND external_thread_id = ? LIMIT 1',
            array((int) $user_id, (string) $platform, (int) $channel_account_id, (string) $external_thread_id)
        );
        $row = $q ? $q->row_array() : null;
        return $row ?: null;
    }
}

if (!function_exists('conversation_get')) {
    /** A conversation by id, optionally scoped to a tenant (returns NULL for another tenant). */
    function conversation_get($conversation_id, $user_id = null)
    {
        $sql = 'SELECT * FROM conversations WHERE id = ?';
        $binds = array((int) $conversation_id);
        if ($user_id !== null) { $sql .= ' AND user_id = ?'; $binds[] = (int) $user_id; }
        $q = conversation_db()->query($sql, $binds);
        $row = $q ? $q->row_array() : null;
        return $row ?: null;
    }
}

if (!function_exists('conversation_customer_for_identity')) {
    /**
     * Customer id of an identity, creating one customer with one identity when missing.
     * Never merges: one identity maps to exactly one customer (brief section 16).
     * Lock order (identities, customers, conversations) matches the link trigger.
     */
    function conversation_customer_for_identity($user_id, $platform, $channel_account_id, $external_id)
    {
        $db = conversation_db();
        $key = array((int) $user_id, (string) $platform, (int) $channel_account_id, (string) $external_id);
        $find = function () use ($db, $key) {
            $q = $db->query('SELECT customer_id FROM customer_identities WHERE user_id = ? AND platform = ? AND channel_account_id = ? AND external_id = ? LIMIT 1', $key);
            $r = $q ? $q->row_array() : null;
            return !empty($r['customer_id']) ? (int) $r['customer_id'] : 0;
        };
        $cid = $find();
        if ($cid > 0) return $cid;

        // Enrich from the legacy subscriber row when there is exactly this identity.
        $sq = $db->query(
            'SELECT id, full_name, phone_number, email, lead_score FROM messenger_bot_subscriber
              WHERE user_id = ? AND social_media = ? AND page_table_id = ? AND subscribe_id = ? ORDER BY id LIMIT 1',
            $key
        );
        $sub = $sq ? $sq->row_array() : null;
        $taken = false;
        if (!empty($sub['id'])) {
            $tq = $db->query('SELECT id FROM customers WHERE legacy_subscriber_id = ? LIMIT 1', array((int) $sub['id']));
            $taken = $tq && $tq->row_array();
        }
        $now = date('Y-m-d H:i:s');
        // PHP 8.1 mysqli throws on SQL errors even with db_debug FALSE, so catch as well as
        // checking the return value.
        // One transaction: the customer row only becomes visible together with its identity,
        // so a concurrent backfill never sees (and removes) a customer without an identity.
        $db->trans_begin();
        try {
            $ok = $db->insert('customers', array(
                'user_id'              => (int) $user_id,
                'display_name'         => (!empty($sub) && trim($sub['full_name']) !== '') ? trim($sub['full_name']) : null,
                'phone'                => (!empty($sub) && $sub['phone_number'] !== '') ? $sub['phone_number'] : null,
                'email'                => (!empty($sub) && $sub['email'] !== '') ? $sub['email'] : null,
                'lead_score'           => !empty($sub) ? (int) $sub['lead_score'] : 0,
                'source'               => (!empty($sub) && !$taken) ? 'subscriber' : 'thread',
                'legacy_subscriber_id' => (!empty($sub) && !$taken) ? (int) $sub['id'] : null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ));
            if (!$ok) { $db->trans_rollback(); return $find(); } // e.g. a concurrent writer took legacy_subscriber_id
            $new_id = (int) $db->insert_id();
            $db->query(
                'INSERT IGNORE INTO customer_identities (customer_id, user_id, platform, external_id, channel_account_id, verified, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?)',
                array($new_id, (int) $user_id, (string) $platform, (string) $external_id, (int) $channel_account_id, $now)
            );
            $cid = $find();
            if ($cid !== $new_id) { $db->trans_rollback(); return $find(); } // lost a race: keep the winner's customer
            $db->trans_commit();

            // Task 24 (brief 22): fires exactly once per genuinely new customer row — the
            // race losers above return before reaching here.
            $ci =& get_instance();
            $ci->load->helper('event');
            if (function_exists('event_emit')) {
                event_emit((int) $user_id, 'customer.created', 'subscriber', $cid, array(
                    'platform'  => $platform,
                    'full_name' => !empty($sub) ? $sub['full_name'] : null,
                ));
            }

            return $cid;
        } catch (Throwable $e) {
            $db->trans_rollback();
            log_message('error', 'conversation_customer_for_identity: ' . $e->getMessage());
            try { return $find(); } catch (Throwable $e2) { return 0; }
        }
    }
}

if (!function_exists('conversation_ai_history_lookup')) {
    /**
     * How an ai_conversation_history (page_id, social_media) pair maps to a conversation's
     * channel account (ADR-001). page_id holds a different thing per channel (see
     * channel_send_helper.php and the get_ai_reply_open_ai() callers):
     *   fb / ig  the Facebook page id          -> facebook_rx_fb_page_info.id (conversation_resolve_account)
     *   tg       telegram_accounts.id          -> used as is
     *   wa       whatsapp_accounts.id (Cloud, Whatsapp_bot) or openwa_accounts.session_id (Openwa_bot)
     *   web      the widget key                -> web conversations always have channel_account_id 0
     * Pure: no database access. NULL for anything else.
     *
     * @return array{platform:string, mode:string, value:string}|null
     */
    function conversation_ai_history_lookup($social_media, $page_id)
    {
        $social_media = (string) $social_media;
        $page_id = trim((string) $page_id);
        if ($social_media === 'web') return array('platform' => 'web', 'mode' => 'fixed', 'value' => '0');
        if ($page_id === '') return null;
        if ($social_media === 'fb' || $social_media === 'ig') return array('platform' => $social_media, 'mode' => 'fb_page', 'value' => $page_id);
        if ($social_media === 'tg') return ctype_digit($page_id) ? array('platform' => 'tg', 'mode' => 'account_id', 'value' => $page_id) : null;
        if ($social_media === 'wa') return array('platform' => 'wa', 'mode' => 'wa_account_or_session', 'value' => $page_id);
        return null;
    }
}

if (!function_exists('conversation_ai_history_link')) {
    /**
     * The extra column for a NEW ai_conversation_history row: array('conversation_id' => id)
     * when the migration has added the column AND the turn's conversation already exists,
     * array() otherwise (the insert is then exactly what it was before). Never creates a
     * conversation, never throws: the AI reply path must not break over bookkeeping.
     *
     * Resolution: (tenant, platform, resolved account, subscribe_id) first. If the account
     * cannot be resolved, a conversation of that tenant and platform with that thread id is used
     * only when there is exactly ONE, so an ambiguous match links nothing.
     */
    function conversation_ai_history_link($user_id, $social_media, $page_id, $subscribe_id)
    {
        static $has_column = null;
        try {
            $db = conversation_db();
            if ($has_column === null) {
                $has_column = $db->table_exists('ai_conversation_history') && $db->field_exists('conversation_id', 'ai_conversation_history');
            }
            if (!$has_column) return array();
            $user_id = (int) $user_id;
            $subscribe_id = (string) $subscribe_id;
            $plan = conversation_ai_history_lookup($social_media, $page_id);
            if ($user_id <= 0 || $subscribe_id === '' || $plan === null) return array();

            $acct = 0;
            if ($plan['mode'] === 'fb_page') {
                $acct = conversation_resolve_account($user_id, $plan['platform'], 0, $plan['value']);
            } elseif ($plan['mode'] === 'account_id') {
                $acct = (int) $plan['value'];
            } elseif ($plan['mode'] === 'wa_account_or_session') {
                if ($db->table_exists('openwa_accounts')) {
                    $q = $db->query('SELECT id FROM openwa_accounts WHERE user_id = ? AND session_id = ? ORDER BY id LIMIT 1',
                        array($user_id, $plan['value']));
                    $r = $q ? $q->row_array() : null;
                    if (!empty($r['id'])) $acct = (int) $r['id'];
                }
                if ($acct === 0 && ctype_digit($plan['value'])) $acct = (int) $plan['value'];
            }

            if ($acct > 0 || $plan['mode'] === 'fixed') {
                $conv = conversation_find($user_id, $plan['platform'], $acct, $subscribe_id);
                if ($conv) return array('conversation_id' => (int) $conv['id']);
                if ($plan['mode'] === 'fixed') return array();
            }

            $q = $db->query('SELECT id FROM conversations WHERE user_id = ? AND platform = ? AND external_thread_id = ? LIMIT 2',
                array($user_id, $plan['platform'], $subscribe_id));
            $rows = $q ? $q->result_array() : array();
            return count($rows) === 1 ? array('conversation_id' => (int) $rows[0]['id']) : array();
        } catch (Throwable $e) {
            log_message('error', 'conversation_ai_history_link: ' . $e->getMessage());
            return array();
        }
    }
}

if (!function_exists('conversation_find_or_create')) {
    /**
     * Conversation id for a thread, created (AI_ACTIVE, open) when missing. Returns 0 on
     * invalid input or a database failure. Idempotent and safe under concurrency: the
     * UNIQUE(user_id, platform, channel_account_id, external_thread_id) key decides.
     */
    function conversation_find_or_create($user_id, $platform, $channel_account_id, $external_thread_id)
    {
        $user_id = (int) $user_id;
        $platform = (string) $platform;
        $external_thread_id = (string) $external_thread_id;
        if ($user_id <= 0 || $external_thread_id === '' || !in_array($platform, conversation_platforms(), true)) return 0;

        try {
            $existing = conversation_find($user_id, $platform, $channel_account_id, $external_thread_id);
            if ($existing && !empty($existing['customer_id'])) return (int) $existing['id'];

            $customer_id = conversation_customer_for_identity($user_id, $platform, $channel_account_id, $external_thread_id);
            $db = conversation_db();
            $now = date('Y-m-d H:i:s');
            $db->query(
                "INSERT INTO conversations (user_id, customer_id, platform, channel_account_id, external_thread_id,
                                            status, handoff_state, unread_count, message_count, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'open', 'AI_ACTIVE', 0, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE customer_id = IFNULL(customer_id, VALUES(customer_id))",
                array($user_id, $customer_id ?: null, $platform, (int) $channel_account_id, $external_thread_id, $now, $now)
            );
            $row = conversation_find($user_id, $platform, $channel_account_id, $external_thread_id);
            return $row ? (int) $row['id'] : 0;
        } catch (Throwable $e) {
            log_message('error', 'conversation_find_or_create: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('conversation_transition')) {
    /**
     * Move a conversation through the handoff state machine (brief section 14).
     *
     * @param int         $conversation_id
     * @param string      $to          target state
     * @param string      $actor_type  system | ai | agent | customer
     * @param int|null    $actor_id    user or team member id for agents, NULL otherwise
     * @param string      $note        free text for the audit row (no customer content)
     * @param int|null    $user_id     tenant guard: when given, another tenant's id is "not found"
     * @return array ok, from, to, noop, paused_until, subscribers_updated, error
     *
     * Same-state requests succeed as a no-op without an audit row. Every real change writes
     * one conversation_events row and mirrors the pause to messenger_bot_subscriber in the
     * same transaction.
     */
    function conversation_transition($conversation_id, $to, $actor_type, $actor_id = null, $note = '', $user_id = null)
    {
        $res = array('ok' => false, 'from' => null, 'to' => $to, 'noop' => false,
                     'paused_until' => null, 'subscribers_updated' => 0, 'error' => '');
        if (!in_array($to, conversation_states(), true)) { $res['error'] = 'unknown state'; return $res; }
        if (!in_array($actor_type, conversation_actor_types(), true)) { $res['error'] = 'unknown actor type'; return $res; }

        $db = conversation_db();
        $db->trans_begin();
        try {
            $sql = 'SELECT * FROM conversations WHERE id = ?';
            $binds = array((int) $conversation_id);
            if ($user_id !== null) { $sql .= ' AND user_id = ?'; $binds[] = (int) $user_id; }
            $q = $db->query($sql . ' FOR UPDATE', $binds);
            $conv = $q ? $q->row_array() : null;
            if (empty($conv)) { $db->trans_rollback(); $res['error'] = 'not found'; return $res; }

            $from = $conv['handoff_state'];
            $res['from'] = $from;
            if ($from === $to) { $db->trans_commit(); $res['ok'] = true; $res['noop'] = true; return $res; }
            if (!conversation_transition_allowed($from, $to, $actor_type)) {
                $db->trans_rollback();
                $res['error'] = "transition {$from} -> {$to} not allowed for {$actor_type}";
                return $res;
            }

            $now = date('Y-m-d H:i:s');
            $status = ($to === 'RESOLVED') ? 'resolved' : 'open';
            $db->query('UPDATE conversations SET handoff_state = ?, status = ?, updated_at = ? WHERE id = ?',
                array($to, $status, $now, (int) $conv['id']));
            $db->insert('conversation_events', array(
                'conversation_id' => (int) $conv['id'],
                'user_id'         => (int) $conv['user_id'],
                'event_type'      => 'state_change',
                'from_state'      => $from,
                'to_state'        => $to,
                'actor_type'      => $actor_type,
                'actor_id'        => $actor_id !== null ? (int) $actor_id : null,
                'note'            => $note !== '' ? mb_substr((string) $note, 0, 500) : null,
                'created_at'      => $now,
            ));

            // Backward compatibility: the legacy bot gates read bot_paused_until.
            $pause = conversation_pause_value_for_state($to);
            $db->query(
                'UPDATE messenger_bot_subscriber SET bot_paused_until = ?
                  WHERE user_id = ? AND social_media = ? AND page_table_id = ? AND subscribe_id = ?',
                array($pause, (int) $conv['user_id'], $conv['platform'], (int) $conv['channel_account_id'], $conv['external_thread_id'])
            );
            $res['subscribers_updated'] = (int) $db->affected_rows();

            if ($db->trans_status() === FALSE) {
                $db->trans_rollback();
                $err = $db->error();
                $res['error'] = 'database error: ' . ($err['message'] ?? '');
                return $res;
            }
            $db->trans_commit();
            $res['ok'] = true;
            $res['paused_until'] = $pause;

            // Task 24 (brief 22): a fact for any rule listening for a handoff change. Never
            // throws, never behind the automation flag (see event_helper.php).
            $ci2 =& get_instance();
            $ci2->load->helper('event');
            if (function_exists('event_emit')) {
                event_emit((int) $conv['user_id'], 'conversation.state_changed', 'conversation', $conv['id'], array(
                    'from'       => $from,
                    'to'         => $to,
                    'actor_type' => $actor_type,
                    'actor_id'   => $actor_id,
                    'platform'   => $conv['platform'],
                ));
            }

            return $res;
        } catch (Throwable $e) {
            // PHP 8.1 mysqli throws on SQL errors even with db_debug FALSE: never leave the
            // transaction open on the shared request connection.
            $db->trans_rollback();
            log_message('error', 'conversation_transition: ' . $e->getMessage());
            $res['ok'] = false;
            $res['error'] = 'database error: ' . $e->getMessage();
            return $res;
        }
    }
}

if (!function_exists('conversation_is_bot_allowed')) {
    /**
     * May the bot auto-reply in this thread? Returns exactly what today's readers compute
     * (Messenger_bot, Openwa_bot, Whatsapp_bot, Telegram_bot, Cron_hub): the first matching
     * messenger_bot_subscriber row is paused when bot_paused_until is set and later than PHP
     * now. No subscriber row means allowed, as today.
     *
     * Deliberately NOT based on handoff_state yet: channels without subscriber rows (Telegram,
     * WA Cloud, web) cannot be paused today, and switching the answer is a separate, flagged
     * step (ADR-001, "Readers switch one at a time").
     */
    function conversation_is_bot_allowed($user_id, $platform, $subscribe_id, $page_table_id = null)
    {
        $sql = 'SELECT bot_paused_until FROM messenger_bot_subscriber WHERE user_id = ? AND social_media = ? AND subscribe_id = ?';
        $binds = array((int) $user_id, (string) $platform, (string) $subscribe_id);
        if ($page_table_id !== null) { $sql .= ' AND page_table_id = ?'; $binds[] = (int) $page_table_id; }
        $q = conversation_db()->query($sql . ' ORDER BY id LIMIT 1', $binds);
        $row = $q ? $q->row_array() : null;
        $paused = isset($row['bot_paused_until']) && $row['bot_paused_until'] !== null
            && $row['bot_paused_until'] > date('Y-m-d H:i:s');
        return !$paused;
    }
}
