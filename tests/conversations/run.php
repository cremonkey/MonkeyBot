<?php
/**
 * Conversation core tests (Phase 9.5). Runs against a disposable DB that has
 * initial_db.sql + all migrations + tests/conversations/seed_synthetic.php + one backfill.
 * See docs/database/conversation-core.md, "Tests".
 *
 *   docker run --rm --network monkeybot-staging_default -e MBCONV_PW=... \
 *     -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/conversations/run.php
 *
 * Test tenants 9901 (A) and 9902 (B) are created and removed here. The backfill test
 * re-backfills seed tenant 9102.
 */

require __DIR__ . '/bootstrap.php';

const TA = 9901;
const TB = 9902;

function cleanup_fixtures()
{
    $ids = TA . ',' . TB;
    foreach (array(
        "DELETE FROM conversation_events WHERE user_id IN ($ids)",
        "DELETE FROM conversations WHERE user_id IN ($ids)",
        "DELETE FROM customer_identities WHERE user_id IN ($ids)",
        "DELETE FROM customers WHERE user_id IN ($ids)",
        "DELETE FROM livechat_messages WHERE user_id IN ($ids) OR page_table_id IN (9901, 9902) OR subscriber_id LIKE 'T-%'",
        "DELETE FROM messenger_bot_subscriber WHERE user_id IN ($ids)",
        "DELETE FROM facebook_rx_fb_page_info WHERE id IN (9901, 9902)",
        "DELETE FROM openwa_accounts WHERE id = 9911",
        "DELETE FROM whatsapp_accounts WHERE id = 9912",
        "DELETE FROM telegram_accounts WHERE id = 9913",
    ) as $sql) {
        if (tdb()->query($sql) === FALSE) throw new RuntimeException('cleanup failed: ' . tdb()->error()['message']);
    }
}

function setup_fixtures()
{
    cleanup_fixtures();
    $raw = mbconv_raw();
    $raw->query("SET SESSION sql_mode = ''");
    foreach (array(
        "INSERT INTO facebook_rx_fb_page_info (id, user_id, page_id, page_name) VALUES (9901, " . TA . ", 'PT9901', 'test A'), (9902, " . TB . ", 'PT9902', 'test B')",
        "INSERT INTO openwa_accounts (id, user_id, api_key, session_id) VALUES (9911, " . TA . ", 'x', 'sess-t-9911')",
        "INSERT INTO whatsapp_accounts (id, user_id, phone_number_id, access_token, verify_token) VALUES (9912, " . TA . ", 'pn-t-9912', 'x', 'vt')",
        "INSERT INTO telegram_accounts (id, user_id, bot_token, webhook_secret) VALUES (9913, " . TA . ", 'tok-t-9913', 'ws')",
        "INSERT INTO messenger_bot_subscriber (user_id, page_table_id, page_id, subscribe_id, full_name, social_media) VALUES
            (" . TA . ", 9901, 'PT9901', 'T-PSID-1', 'عميل اختبار أ', 'fb'),
            (" . TA . ", 9901, 'PT9901', 'T-PSID-2', 'Test A two', 'fb'),
            (" . TB . ", 9902, 'PT9902', 'T-PSID-1', 'Test B one', 'fb'),
            (" . TA . ", 9911, 'sess-t-9911', '201000000001', 'واتساب', 'wa'),
            (" . TA . ", 9913, 'tg-9913', '880001', 'tg user', 'tg')",
    ) as $sql) {
        if (!$raw->query($sql)) throw new RuntimeException('fixture failed: ' . $raw->error);
    }
}

/** Insert a livechat row exactly the way a writer does (CI query builder, prod connection). */
function log_row(array $row)
{
    if (!tdb()->insert('livechat_messages', $row)) throw new RuntimeException('insert failed: ' . tdb()->error()['message']);
    $id = (int) tdb()->insert_id();
    $m = rows('SELECT id, conversation_id FROM livechat_messages WHERE id = ?', array($id));
    return array($id, $m ? (int) $m[0]['conversation_id'] : 0);
}

function now() { return date('Y-m-d H:i:s'); }

function sub_pause($user_id, $social, $subscribe_id, $page_table_id)
{
    return scalar('SELECT bot_paused_until FROM messenger_bot_subscriber WHERE user_id = ? AND social_media = ? AND subscribe_id = ? AND page_table_id = ?',
        array($user_id, $social, $subscribe_id, $page_table_id));
}

/** The legacy reader formula (Messenger_bot, Openwa_bot, Whatsapp_bot, Telegram_bot, Cron_hub). */
function legacy_paused($row)
{
    return isset($row['bot_paused_until']) && $row['bot_paused_until'] !== null && $row['bot_paused_until'] > date('Y-m-d H:i:s');
}

function events_count($conv_id) { return (int) scalar('SELECT COUNT(*) FROM conversation_events WHERE conversation_id = ?', array($conv_id)); }

setup_fixtures();

// -----------------------------------------------------------------------------------------
t_run('schema: tables, column, index and link trigger exist', function () {
    foreach (array('conversations', 'conversation_events', 'customers', 'customer_identities', 'customer_merge_log') as $t) {
        t_ok(tdb()->table_exists($t), "table {$t}");
    }
    t_ok(tdb()->field_exists('conversation_id', 'livechat_messages'), 'livechat_messages.conversation_id');
    t_eq(1, (int) scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'livechat_messages' AND index_name = 'idx_lm_conversation' AND seq_in_index = 1"), 'index idx_lm_conversation');
    t_eq(1, (int) scalar("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_livechat_messages_link_conversation'"), 'trigger');
});

$conv = array();

t_run('trigger: a new livechat row is linked for every platform and every writer shape', function () use (&$conv) {
    $t = now();
    $cases = array(
        // name => [row as the writer builds it, expected key user/platform/account/thread]
        'fb inbound (Home::insert_livechat_data)' => array(array('user_id' => TA, 'subscriber_id' => 'T-PSID-1', 'page_table_id' => 9901, 'fb_page_id' => 'PT9901', 'sender' => 'user', 'platform' => 'fb', 'message_content' => 'السلام عليكم 👋', 'conversation_time' => $t, 'fb_message_id' => 'm_1', 'message_status' => 'read'), array(TA, 'fb', 9901, 'T-PSID-1')),
        'fb bot (channel_log_message: page_table_id 0 + fb_page_id)' => array(array('user_id' => TA, 'subscriber_id' => 'T-PSID-1', 'page_table_id' => 0, 'fb_page_id' => 'PT9901', 'sender' => 'bot', 'platform' => 'fb', 'agent_name' => 'Bot', 'message_content' => 'أهلاً بك', 'conversation_time' => $t, 'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0'), array(TA, 'fb', 9901, 'T-PSID-1')),
        'fb system (Home::system_message_insert_into_conversation, no user_id)' => array(array('subscriber_id' => 'T-PSID-1', 'page_table_id' => 9901, 'sender' => 'system', 'agent_name' => 'Bot', 'message_content' => 'Conversation was assigned to X', 'conversation_time' => $t, 'platform' => 'fb'), array(TA, 'fb', 9901, 'T-PSID-1')),
        'ig inbound' => array(array('user_id' => TA, 'subscriber_id' => 'T-IG-1', 'page_table_id' => 9901, 'fb_page_id' => 'PT9901', 'sender' => 'user', 'platform' => 'ig', 'message_content' => 'hi 😀', 'conversation_time' => $t), array(TA, 'ig', 9901, 'T-IG-1')),
        'wa OpenWA (Openwa_bot::log_msg)' => array(array('user_id' => TA, 'subscriber_id' => '201000000001', 'page_table_id' => 9911, 'fb_page_id' => '', 'sender' => 'user', 'platform' => 'wa', 'agent_name' => '', 'message_content' => 'مرحبا', 'conversation_time' => $t, 'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0'), array(TA, 'wa', 9911, '201000000001')),
        'wa Cloud (Whatsapp_bot::log_msg)' => array(array('user_id' => TA, 'subscriber_id' => '201000000002', 'page_table_id' => 9912, 'fb_page_id' => '', 'sender' => 'user', 'platform' => 'wa', 'agent_name' => '', 'message_content' => 'price?', 'conversation_time' => $t, 'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0'), array(TA, 'wa', 9912, '201000000002')),
        'tg (Telegram_bot::log_msg)' => array(array('user_id' => TA, 'subscriber_id' => '880001', 'page_table_id' => 9913, 'fb_page_id' => '', 'sender' => 'user', 'platform' => 'tg', 'agent_name' => '', 'message_content' => 'hello', 'conversation_time' => $t, 'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0'), array(TA, 'tg', 9913, '880001')),
        'web (Webchat::log_msg)' => array(array('user_id' => TA, 'subscriber_id' => 'T-ws-session', 'page_table_id' => 0, 'fb_page_id' => '', 'sender' => 'user', 'platform' => 'web', 'agent_name' => '', 'message_content' => 'أريد الحجز', 'conversation_time' => $t, 'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0'), array(TA, 'web', 0, 'T-ws-session')),
    );
    foreach ($cases as $name => $c) {
        list($id, $cid) = log_row($c[0]);
        t_ok($cid > 0, "{$name}: conversation_id set");
        t_eq((int) scalar('SELECT MAX(id) FROM livechat_messages'), $id, "{$name}: insert_id() is the livechat row id (trigger does not leak LAST_INSERT_ID)");
        $row = rows('SELECT * FROM conversations WHERE id = ?', array($cid));
        t_ok(!empty($row), "{$name}: conversation exists");
        if (empty($row)) continue;
        list($u, $p, $a, $x) = $c[1];
        t_eq(array($u, $p, $a, $x), array((int) $row[0]['user_id'], $row[0]['platform'], (int) $row[0]['channel_account_id'], $row[0]['external_thread_id']), "{$name}: conversation key");
        t_ok((int) $row[0]['customer_id'] > 0, "{$name}: conversation has a customer");
        t_eq(1, (int) scalar('SELECT COUNT(*) FROM customer_identities WHERE customer_id = ?', array($row[0]['customer_id'])), "{$name}: customer has exactly one identity");
        $conv["{$p}:{$a}:{$x}"] = $cid;
    }
    $fb = rows('SELECT * FROM conversations WHERE id = ?', array($conv['fb:9901:T-PSID-1']))[0];
    t_eq(3, (int) $fb['message_count'], 'fb: inbound, bot and system rows share one conversation');
    t_eq(0, (int) $fb['unread_count'], 'fb: a bot reply resets unread, a system row leaves it');
    t_ok($fb['last_inbound_at'] !== null, 'fb: last_inbound_at set');
    t_eq('AI_ACTIVE', $fb['handoff_state'], 'fb: new conversation starts AI_ACTIVE');
    list(, $cid2) = log_row(array('user_id' => TA, 'subscriber_id' => 'T-PSID-1', 'page_table_id' => 9901, 'fb_page_id' => 'PT9901', 'sender' => 'user', 'platform' => 'fb', 'message_content' => 'x', 'conversation_time' => now()));
    list(, $cid3) = log_row(array('user_id' => TA, 'subscriber_id' => 'T-PSID-1', 'page_table_id' => 9901, 'fb_page_id' => 'PT9901', 'sender' => 'user', 'platform' => 'fb', 'message_content' => 'y', 'conversation_time' => now()));
    t_eq(2, (int) scalar('SELECT unread_count FROM conversations WHERE id = ?', array($cid3)), 'fb: two customer messages after the bot => unread 2');
    $sub_cust = rows("SELECT c.* FROM customers c JOIN customer_identities ci ON ci.customer_id = c.id WHERE ci.user_id = ? AND ci.platform = 'fb' AND ci.external_id = 'T-PSID-1'", array(TA));
    t_eq(1, count($sub_cust), 'fb: one customer for the identity');
});

t_run('trigger: tenant isolation - the same PSID in another tenant is another conversation and customer', function () use (&$conv) {
    list(, $cb) = log_row(array('user_id' => TB, 'subscriber_id' => 'T-PSID-1', 'page_table_id' => 9902, 'fb_page_id' => 'PT9902', 'sender' => 'user', 'platform' => 'fb', 'message_content' => 'B', 'conversation_time' => now()));
    t_ok($cb > 0 && $cb !== $conv['fb:9901:T-PSID-1'], 'different conversation');
    t_eq(TB, (int) scalar('SELECT user_id FROM conversations WHERE id = ?', array($cb)), 'owned by tenant B');
    $ca_cust = (int) scalar('SELECT customer_id FROM conversations WHERE id = ?', array($conv['fb:9901:T-PSID-1']));
    $cb_cust = (int) scalar('SELECT customer_id FROM conversations WHERE id = ?', array($cb));
    t_ok($ca_cust !== $cb_cust, 'different customer');
    t_eq(TB, (int) scalar('SELECT user_id FROM customers WHERE id = ?', array($cb_cust)), 'customer owned by tenant B');
    $conv['B:fb:9902:T-PSID-1'] = $cb;
    // system row of tenant B written without user_id resolves to B through its page
    list(, $cs) = log_row(array('subscriber_id' => 'T-PSID-1', 'page_table_id' => 9902, 'sender' => 'system', 'agent_name' => 'Bot', 'message_content' => 's', 'conversation_time' => now(), 'platform' => 'fb'));
    t_eq($cb, $cs, 'system row without user_id links to the page owner (B), not A');
});

t_run('transitions: allowed and denied edges for every state x state x actor', function () {
    $cid = conversation_find_or_create(TA, 'web', 0, 'T-ws-matrix');
    t_ok($cid > 0, 'find_or_create');
    $edges = array('AI_ACTIVE>WAITING_FOR_HUMAN', 'WAITING_FOR_HUMAN>HUMAN_ACTIVE', 'HUMAN_ACTIVE>AI_ASSIST',
                   'AI_ASSIST>HUMAN_ACTIVE', 'HUMAN_ACTIVE>RESOLVED', 'RESOLVED>AI_ACTIVE');
    $states = array('AI_ACTIVE', 'WAITING_FOR_HUMAN', 'HUMAN_ACTIVE', 'AI_ASSIST', 'RESOLVED');
    $actors = array('system', 'ai', 'agent', 'customer');
    $checked = 0; $allowed = 0;
    foreach ($states as $from) {
        foreach ($states as $to) {
            foreach ($actors as $actor) {
                tdb()->query('UPDATE conversations SET handoff_state = ? WHERE id = ?', array($from, $cid));
                $before = events_count($cid);
                $res = conversation_transition($cid, $to, $actor, $actor === 'agent' ? 7 : null, 'matrix');
                $expect_ok = ($from === $to) || in_array("{$from}>{$to}", $edges, true) || ($to === 'AI_ACTIVE' && $actor === 'agent');
                $state = scalar('SELECT handoff_state FROM conversations WHERE id = ?', array($cid));
                t_eq($expect_ok, $res['ok'], "{$from} -> {$to} by {$actor}: ok");
                t_eq($expect_ok ? $to : $from, $state, "{$from} -> {$to} by {$actor}: resulting state");
                t_eq(($expect_ok && $from !== $to) ? $before + 1 : $before, events_count($cid), "{$from} -> {$to} by {$actor}: audit rows");
                if ($from === $to) t_ok($res['noop'], "{$from} -> {$to}: same state is a no-op");
                if ($expect_ok && $from !== $to) $allowed++;
                $checked++;
            }
        }
    }
    t_eq(100, $checked, 'combinations checked');
    // 6 edges x 4 actors + agent return-to-bot from the 3 states without a listed edge to AI_ACTIVE
    t_eq(6 * 4 + 3, $allowed, 'allowed real transitions');
    $bad = conversation_transition($cid, 'NOT_A_STATE', 'agent');
    t_ok(!$bad['ok'] && $bad['error'] === 'unknown state', 'unknown state rejected');
    $bad = conversation_transition($cid, 'AI_ACTIVE', 'robot');
    t_ok(!$bad['ok'] && $bad['error'] === 'unknown actor type', 'unknown actor rejected');
    $bad = conversation_transition(999999999, 'AI_ACTIVE', 'agent');
    t_ok(!$bad['ok'] && $bad['error'] === 'not found', 'missing conversation rejected');
});

t_run('transitions: audit row content and status column', function () use (&$conv) {
    $cid = $conv['fb:9901:T-PSID-1'];
    tdb()->query("UPDATE conversations SET handoff_state = 'AI_ACTIVE', status = 'open' WHERE id = ?", array($cid));
    $r = conversation_transition($cid, 'WAITING_FOR_HUMAN', 'ai', null, 'customer asked for a human');
    t_ok($r['ok'], 'AI escalates');
    $ev = rows('SELECT * FROM conversation_events WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', array($cid))[0];
    t_eq(array('state_change', 'AI_ACTIVE', 'WAITING_FOR_HUMAN', 'ai', null, 'customer asked for a human', TA),
         array($ev['event_type'], $ev['from_state'], $ev['to_state'], $ev['actor_type'], $ev['actor_id'], $ev['note'], (int) $ev['user_id']), 'event row');
    t_ok(conversation_transition($cid, 'HUMAN_ACTIVE', 'agent', 42, 'accepted')['ok'], 'agent accepts');
    t_eq('42', rows('SELECT actor_id FROM conversation_events WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', array($cid))[0]['actor_id'], 'actor id recorded');
    t_ok(conversation_transition($cid, 'RESOLVED', 'agent', 42)['ok'], 'agent resolves');
    t_eq('resolved', scalar('SELECT status FROM conversations WHERE id = ?', array($cid)), 'status resolved');
    // a bot row does not reopen, a customer row does (brief section 14)
    log_row(array('user_id' => TA, 'subscriber_id' => 'T-PSID-1', 'page_table_id' => 0, 'fb_page_id' => 'PT9901', 'sender' => 'bot', 'platform' => 'fb', 'message_content' => 'bye', 'conversation_time' => now()));
    t_eq('RESOLVED', scalar('SELECT handoff_state FROM conversations WHERE id = ?', array($cid)), 'bot message keeps RESOLVED');
    $n = events_count($cid);
    log_row(array('user_id' => TA, 'subscriber_id' => 'T-PSID-1', 'page_table_id' => 9901, 'fb_page_id' => 'PT9901', 'sender' => 'user', 'platform' => 'fb', 'message_content' => 'تاني', 'conversation_time' => now()));
    $c = rows('SELECT handoff_state, status FROM conversations WHERE id = ?', array($cid))[0];
    t_eq(array('AI_ACTIVE', 'open'), array($c['handoff_state'], $c['status']), 'customer message reopens RESOLVED -> AI_ACTIVE');
    $ev = rows('SELECT * FROM conversation_events WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', array($cid))[0];
    t_eq(array($n + 1, 'RESOLVED', 'AI_ACTIVE', 'customer'), array(events_count($cid), $ev['from_state'], $ev['to_state'], $ev['actor_type']), 'reopen is audited');
});

t_run('compat: transitions mirror bot_paused_until and the legacy readers agree', function () use (&$conv) {
    $cid = $conv['fb:9901:T-PSID-1'];
    tdb()->query("UPDATE conversations SET handoff_state = 'AI_ACTIVE' WHERE id = ?", array($cid));
    tdb()->query("UPDATE messenger_bot_subscriber SET bot_paused_until = NULL WHERE user_id IN (" . TA . ',' . TB . ')');
    $expect6h = time() + 6 * 3600;
    $r = conversation_transition($cid, 'WAITING_FOR_HUMAN', 'ai');
    t_eq(1, $r['subscribers_updated'], 'WAITING_FOR_HUMAN updates exactly the scoped subscriber row');
    $p = sub_pause(TA, 'fb', 'T-PSID-1', 9901);
    t_ok($p !== null && abs(strtotime($p) - $expect6h) <= 60, 'WAITING_FOR_HUMAN pauses for 6h');
    t_ok(!conversation_is_bot_allowed(TA, 'fb', 'T-PSID-1', 9901), 'bot not allowed while waiting');
    t_eq(null, sub_pause(TB, 'fb', 'T-PSID-1', 9902), 'tenant B subscriber with the same PSID untouched');
    t_ok(conversation_is_bot_allowed(TB, 'fb', 'T-PSID-1', 9902), 'tenant B bot still allowed');
    t_eq(null, sub_pause(TA, 'fb', 'T-PSID-2', 9901), 'other subscriber of the same page untouched');

    conversation_transition($cid, 'HUMAN_ACTIVE', 'agent', 5);
    t_ok(!conversation_is_bot_allowed(TA, 'fb', 'T-PSID-1', 9901), 'HUMAN_ACTIVE pauses');
    conversation_transition($cid, 'AI_ASSIST', 'agent', 5);
    t_ok(!conversation_is_bot_allowed(TA, 'fb', 'T-PSID-1', 9901), 'AI_ASSIST keeps the pause (AI drafts for the agent only)');
    conversation_transition($cid, 'HUMAN_ACTIVE', 'agent', 5);
    conversation_transition($cid, 'RESOLVED', 'agent', 5);
    t_eq(null, sub_pause(TA, 'fb', 'T-PSID-1', 9901), 'RESOLVED clears bot_paused_until');
    t_ok(conversation_is_bot_allowed(TA, 'fb', 'T-PSID-1', 9901), 'bot allowed after RESOLVED');
    tdb()->query("UPDATE conversations SET handoff_state = 'AI_ACTIVE' WHERE id = ?", array($cid));
    conversation_transition($cid, 'WAITING_FOR_HUMAN', 'ai');
    conversation_transition($cid, 'AI_ACTIVE', 'agent', 5, 'return to bot');
    t_eq(null, sub_pause(TA, 'fb', 'T-PSID-1', 9901), 'agent return-to-bot clears bot_paused_until');

    // Messenger_bot-style reader (subscribe_id only) on a PSID unique to tenant A
    $c2 = conversation_find_or_create(TA, 'fb', 9901, 'T-PSID-2');
    conversation_transition($c2, 'WAITING_FOR_HUMAN', 'ai');
    $row = rows("SELECT bot_paused_until FROM messenger_bot_subscriber WHERE subscribe_id = 'T-PSID-2' LIMIT 1");
    t_ok(legacy_paused($row[0]), 'Messenger_bot reader sees the pause');
    // Openwa_bot-style reader (subscribe_id + social_media + page_table_id)
    $cw = $conv['wa:9911:201000000001'];
    conversation_transition($cw, 'WAITING_FOR_HUMAN', 'ai');
    $row = rows("SELECT bot_paused_until FROM messenger_bot_subscriber WHERE subscribe_id = '201000000001' AND social_media = 'wa' AND page_table_id = 9911 LIMIT 1");
    t_ok(legacy_paused($row[0]), 'Openwa_bot reader sees the pause');
    t_ok(!conversation_is_bot_allowed(TA, 'wa', '201000000001', 9911), 'helper agrees (wa)');
    // Telegram_bot-style reader (subscribe_id + social_media)
    $ct = $conv['tg:9913:880001'];
    conversation_transition($ct, 'WAITING_FOR_HUMAN', 'ai');
    $row = rows("SELECT bot_paused_until FROM messenger_bot_subscriber WHERE subscribe_id = '880001' AND social_media = 'tg' LIMIT 1");
    t_ok(legacy_paused($row[0]), 'Telegram_bot reader sees the pause');
    // channels without a subscriber row cannot be paused today; the helper keeps that answer
    $cweb = $conv['web:0:T-ws-session'];
    $r = conversation_transition($cweb, 'WAITING_FOR_HUMAN', 'ai');
    t_ok($r['ok'] && $r['subscribers_updated'] === 0, 'web: state changes, no subscriber row to pause');
    t_ok(conversation_is_bot_allowed(TA, 'web', 'T-ws-session', 0), 'web: legacy answer (allowed) preserved');
});

t_run('compat: conversation_is_bot_allowed equals the legacy formula for every subscriber', function () {
    $mismatch = 0; $paused = 0; $n = 0;
    foreach (rows('SELECT user_id, social_media, subscribe_id, page_table_id, bot_paused_until FROM messenger_bot_subscriber ORDER BY id') as $s) {
        $legacy = !legacy_paused($s);
        if (!$legacy) $paused++;
        if (conversation_is_bot_allowed($s['user_id'], $s['social_media'], $s['subscribe_id'], $s['page_table_id']) !== $legacy) $mismatch++;
        $n++;
    }
    t_ok($n > 200, "subscribers checked ({$n})");
    t_ok($paused > 0, "some paused ({$paused})");
    t_eq(0, $mismatch, 'no mismatches');
    t_ok(conversation_is_bot_allowed(TA, 'fb', 'no-such-subscriber'), 'no subscriber row => allowed (as today)');
});

t_run('tenant isolation: another tenant cannot read or move a conversation', function () use (&$conv) {
    $cid = $conv['fb:9901:T-PSID-1'];
    $state = scalar('SELECT handoff_state FROM conversations WHERE id = ?', array($cid));
    $n = events_count($cid);
    $r = conversation_transition($cid, 'WAITING_FOR_HUMAN', 'agent', 1, 'cross tenant', TB);
    t_ok(!$r['ok'] && $r['error'] === 'not found', 'transition with tenant B guard is rejected');
    t_eq($state, scalar('SELECT handoff_state FROM conversations WHERE id = ?', array($cid)), 'state unchanged');
    t_eq($n, events_count($cid), 'no audit row');
    t_eq(null, conversation_get($cid, TB), 'conversation_get scoped to B returns nothing');
    t_ok(conversation_get($cid, TA) !== null, 'conversation_get scoped to A returns it');
});

t_run('find_or_create: idempotent, one customer per identity, enriched from the subscriber', function () {
    $a = conversation_find_or_create(TA, 'fb', 9901, 'T-PSID-new');
    $b = conversation_find_or_create(TA, 'fb', 9901, 'T-PSID-new');
    t_ok($a > 0 && $a === $b, 'same id twice');
    t_eq(0, conversation_find_or_create(TA, 'tiktok', 0, 'x'), 'unknown platform rejected');
    t_eq(0, conversation_find_or_create(0, 'fb', 0, 'x'), 'missing tenant rejected');
    t_eq(0, conversation_find_or_create(TA, 'fb', 0, ''), 'empty thread rejected');
    $sub_id = (int) scalar("SELECT id FROM messenger_bot_subscriber WHERE user_id = ? AND subscribe_id = 'T-PSID-2'", array(TA));
    $c = rows('SELECT u.* FROM conversations c JOIN customers u ON u.id = c.customer_id WHERE c.user_id = ? AND c.external_thread_id = ?', array(TA, 'T-PSID-2'))[0];
    t_eq('Test A two', $c['display_name'], 'display_name from subscriber');
    t_eq($sub_id, (int) $c['legacy_subscriber_id'], 'legacy_subscriber_id set');
});

t_run('trigger: an SQL error inside the trigger never loses the message (row stays unlinked, backfill links it)', function () {
    // CHECK violation on the conversations insert = a catchable error inside the trigger body
    tdb()->query("ALTER TABLE conversations ADD CONSTRAINT chk_test_trigger_failure CHECK (external_thread_id <> 'T-fail-thread')");
    try {
        list($id, $cid) = log_row(array('user_id' => TA, 'subscriber_id' => 'T-fail-thread', 'page_table_id' => 0, 'fb_page_id' => '', 'sender' => 'user', 'platform' => 'web', 'message_content' => 'x', 'conversation_time' => now()));
        t_ok($id > 0, 'message inserted');
        t_eq(0, $cid, 'conversation_id left NULL');
    } finally {
        tdb()->query('ALTER TABLE conversations DROP CONSTRAINT chk_test_trigger_failure');
    }
    conversation_backfill_run(1000);
    t_ok((int) scalar('SELECT conversation_id FROM livechat_messages WHERE id = ?', array($id)) > 0, 'backfill links it afterwards');
});

t_run('trigger: a short lock on the conversation (a transition) delays the insert, then it links', function () use (&$conv) {
    $cid = $conv['web:0:T-ws-session'];
    $raw = mbconv_raw();
    tdb()->trans_begin();
    tdb()->query('SELECT id FROM conversations WHERE id = ? FOR UPDATE', array($cid));
    $raw->query("INSERT INTO livechat_messages (user_id, subscriber_id, page_table_id, fb_page_id, sender, platform, message_content, conversation_time)
                 VALUES (" . TA . ", 'T-ws-session', 0, '', 'user', 'web', 'during lock', NOW())", MYSQLI_ASYNC);
    usleep(700000);
    tdb()->trans_commit();
    $ok = $raw->reap_async_query();
    $id = (int) $raw->insert_id;
    t_ok($ok !== false && $id > 0, 'insert completed once the lock was released');
    t_eq($cid, (int) scalar('SELECT conversation_id FROM livechat_messages WHERE id = ?', array($id)), 'and was linked');
});

t_run('trigger: KNOWN LIMIT - a lock wait timeout inside the trigger fails the INSERT (not catchable in a trigger)', function () use (&$conv) {
    $cid = $conv['web:0:T-ws-session'];
    $raw = mbconv_raw();
    $raw->query('START TRANSACTION');
    $raw->query('SELECT id FROM conversations WHERE id = ' . (int) $cid . ' FOR UPDATE');
    tdb()->query('SET SESSION innodb_lock_wait_timeout = 1');
    $failed = false;
    try {
        $failed = !tdb()->insert('livechat_messages', array('user_id' => TA, 'subscriber_id' => 'T-ws-session', 'page_table_id' => 0, 'fb_page_id' => '', 'sender' => 'user', 'platform' => 'web', 'message_content' => 'timeout', 'conversation_time' => now()));
    } catch (Throwable $e) {
        $failed = strpos($e->getMessage(), 'Lock wait timeout') !== false;
    } finally {
        $raw->query('ROLLBACK');
        tdb()->query('SET SESSION innodb_lock_wait_timeout = 50');
    }
    t_ok($failed, 'insert fails with 1205 - so no code may hold a conversations row lock for long (see ADR-001)');
});

/** Key-level signature of a tenant's conversation data, independent of surrogate ids. */
function tenant_signature($uid)
{
    $rows = rows("SELECT c.platform, c.channel_account_id, c.external_thread_id, c.message_count, c.last_message_at, c.last_inbound_at,
                         c.unread_count, c.assigned_user_id, u.display_name, u.phone, u.email, u.lead_score, u.legacy_subscriber_id,
                         (SELECT COUNT(*) FROM livechat_messages m WHERE m.conversation_id = c.id) AS linked
                    FROM conversations c LEFT JOIN customers u ON u.id = c.customer_id
                   WHERE c.user_id = ? ORDER BY c.platform, c.channel_account_id, c.external_thread_id", array($uid));
    return md5(json_encode($rows)) . ':' . count($rows);
}

function tenant_counts($uid)
{
    return array(
        'conversations' => (int) scalar('SELECT COUNT(*) FROM conversations WHERE user_id = ?', array($uid)),
        'customers' => (int) scalar('SELECT COUNT(*) FROM customers WHERE user_id = ?', array($uid)),
        'identities' => (int) scalar('SELECT COUNT(*) FROM customer_identities WHERE user_id = ?', array($uid)),
        'linked' => (int) scalar('SELECT COUNT(*) FROM livechat_messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ?', array($uid)),
        'human_active' => (int) scalar("SELECT COUNT(*) FROM conversations WHERE user_id = ? AND handoff_state = 'HUMAN_ACTIVE'", array($uid)),
        'events' => (int) scalar('SELECT COUNT(*) FROM conversation_events WHERE user_id = ?', array($uid)),
    );
}

t_run('backfill: resumable and idempotent (re-backfill seed tenant 9102 from scratch)', function () {
    $uid = 9102;
    conversation_backfill_run(5000); // settle anything left by earlier tests
    $sig0 = tenant_signature($uid);
    $cnt0 = tenant_counts($uid);
    t_ok($cnt0['conversations'] > 50 && $cnt0['linked'] > 1000, 'seed tenant has data (' . json_encode($cnt0) . ')');
    // Undo the backfill for this tenant, as if it had never run.
    tdb()->query('UPDATE livechat_messages m JOIN conversations c ON c.id = m.conversation_id SET m.conversation_id = NULL WHERE c.user_id = ?', array($uid));
    foreach (array('conversation_events', 'conversations', 'customer_identities', 'customers') as $t) tdb()->query("DELETE FROM {$t} WHERE user_id = ?", array($uid));
    t_eq(0, tenant_counts($uid)['linked'], 'tenant unlinked');

    // interrupted run: one small batch only
    $r1 = conversation_backfill_run(100, 1);
    t_ok(!$r1['complete'] && $r1['messages_linked'] > 0, 'first run stops after one batch');
    $mid = tenant_counts($uid)['linked'];
    t_ok($mid > 0 && $mid < $cnt0['linked'], "partial progress ({$mid} of {$cnt0['linked']})");
    // resume
    $r2 = conversation_backfill_run(100);
    t_ok($r2['complete'], 'resumed run completes');
    $cnt1 = tenant_counts($uid);
    $sig1 = tenant_signature($uid);
    // run again: must change nothing
    $r3 = conversation_backfill_run(100);
    $cnt2 = tenant_counts($uid);
    $sig2 = tenant_signature($uid);
    t_eq(0, $r3['customers_created'] + $r3['identities_created'] + $r3['conversations_created'] + $r3['messages_linked']
            + $r3['conversations_recounted'] + $r3['handoff_human_active'] + $r3['assignments_copied'] + $r3['customers_enriched'], 'third run does no work');
    t_eq($cnt1, $cnt2, 'counts identical after a second full run');
    t_eq($sig1, $sig2, 'key-level signature identical after a second full run');
    foreach (array('conversations', 'customers', 'identities', 'linked') as $k) t_eq($cnt0[$k], $cnt1[$k], "{$k} equal to the original backfill");
    t_eq($sig0, $sig1, 'signature equal to the original backfill');
    $expect_human = (int) scalar("SELECT COUNT(*) FROM conversations c JOIN messenger_bot_subscriber s
        ON s.user_id = c.user_id AND s.social_media = c.platform AND s.page_table_id = c.channel_account_id AND s.subscribe_id = c.external_thread_id
        WHERE c.user_id = ? AND s.bot_paused_until > ?", array($uid, now()));
    t_eq($expect_human, $cnt1['human_active'], 'HUMAN_ACTIVE exactly where bot_paused_until is in the future');
    t_eq($cnt1['human_active'], $cnt1['events'], 'one backfill_state audit row per HUMAN_ACTIVE conversation');
});

t_run('verification report: every check at its expected value', function () {
    $r = conversation_backfill_report();
    foreach ($r['checks'] as $c) {
        list($label, $val, $exp) = $c;
        if ($exp === null) continue;
        if (strpos($label, 'wa account-id collisions') === 0) { t_eq(1, $val, 'synthetic seed has exactly one planted wa account-id collision'); continue; }
        t_eq($exp, $val, $label);
    }
    foreach ($r['per_platform'] as $p => $v) {
        t_eq($v['source_threads'], $v['conversations_with_messages'], "{$p}: source threads = conversations with messages");
        t_eq($v['messages_linked'], $v['message_count_sum'], "{$p}: linked messages = message_count sum");
    }
});

cleanup_fixtures();
conversation_backfill_run(5000); // leave the shared seed tenants consistent

$p = $GLOBALS['__t']['pass']; $f = $GLOBALS['__t']['fail'];
echo "\n{$p} assertions passed, {$f} failed\n";
exit($f === 0 ? 0 : 1);
