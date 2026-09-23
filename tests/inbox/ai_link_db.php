<?php
/**
 * conversation_ai_history_link() against a real, disposable MariaDB (initial_db.sql + every
 * migration, so the livechat link trigger and ai_conversation_history.conversation_id exist).
 * Uses the conversation-core test bootstrap (CodeIgniter's real query builder, no app boot).
 * Run by docker/scripts/staging_inbox_tests.sh inside the isolated test stack:
 *
 *   docker exec -e MBCONV_HOST=db -e MBCONV_USER=... -e MBCONV_PW=... -e MBCONV_DB=..._test \
 *     <app> php /var/www/html/tests/inbox/ai_link_db.php
 *
 * Tenants 9951 / 9952 and account ids 99501-99505 are created and removed here.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../conversations/bootstrap.php';

const LA = 9951;
const LB = 9952;

function ai_link_cleanup()
{
    foreach (array(
        'DELETE FROM conversation_events WHERE user_id IN (9951, 9952)',
        'DELETE FROM conversations WHERE user_id IN (9951, 9952)',
        'DELETE FROM customer_identities WHERE user_id IN (9951, 9952)',
        'DELETE FROM customers WHERE user_id IN (9951, 9952)',
        'DELETE FROM livechat_messages WHERE user_id IN (9951, 9952)',
        'DELETE FROM facebook_rx_fb_page_info WHERE id IN (99501, 99506)',
        'DELETE FROM telegram_accounts WHERE id = 99502',
        'DELETE FROM openwa_accounts WHERE id = 99503',
        'DELETE FROM whatsapp_accounts WHERE id = 99504',
    ) as $sql) tdb()->query($sql);
}

function ai_link_msg($uid, $sub, $acct, $fb_page, $platform)
{
    $ok = tdb()->insert('livechat_messages', array(
        'user_id' => $uid, 'subscriber_id' => $sub, 'page_table_id' => $acct, 'fb_page_id' => $fb_page,
        'sender' => 'user', 'platform' => $platform, 'agent_name' => '', 'message_content' => 'hi',
        'conversation_time' => date('Y-m-d H:i:s'), 'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0',
    ));
    if (!$ok) throw new RuntimeException('seed insert failed');
    return (int) scalar('SELECT conversation_id FROM livechat_messages WHERE id = ?', array(tdb()->insert_id()));
}

ai_link_cleanup();
tdb()->query("INSERT INTO facebook_rx_fb_page_info (id,user_id,facebook_rx_fb_user_info_id,page_id,page_name,page_access_token,has_instagram,bot_enabled)
              VALUES (99501," . LA . ",0,'PG99501','A page','t',1,'1'), (99506," . LB . ",0,'PG99506','B page','t',0,'1')");
tdb()->query("INSERT INTO telegram_accounts (id,user_id,bot_token,webhook_secret,status) VALUES (99502," . LA . ",'x','y','1')");
tdb()->query("INSERT INTO openwa_accounts (id,user_id,base_url,api_key,session_id,status) VALUES (99503," . LA . ",'http://x','k','sess-99503','1')");
tdb()->query("INSERT INTO whatsapp_accounts (id,user_id,phone_number_id,access_token,verify_token,status) VALUES (99504," . LA . ",'pn','t','v','1')");

$fb  = ai_link_msg(LA, 'PSID-1', 99501, 'PG99501', 'fb');
$ig  = ai_link_msg(LA, 'IGSID-1', 99501, 'PG99501', 'ig');
$tg  = ai_link_msg(LA, 'CHAT-1', 99502, '', 'tg');
$owa = ai_link_msg(LA, '201000000001', 99503, '', 'wa');
$wac = ai_link_msg(LA, '201000000002', 99504, '', 'wa');
$web = ai_link_msg(LA, 'web_sess_1', 0, '', 'web');
$fbB = ai_link_msg(LB, 'PSID-1', 99506, 'PG99506', 'fb');

t_run('seed: the link trigger created every conversation', function () use ($fb, $ig, $tg, $owa, $wac, $web, $fbB) {
    foreach (array($fb, $ig, $tg, $owa, $wac, $web, $fbB) as $i => $id) t_ok($id > 0, "conversation #$i");
});

t_run('ai_conversation_history has the conversation_id column (migration 2026-09-30c)', function () {
    t_ok(tdb()->field_exists('conversation_id', 'ai_conversation_history'), 'column exists');
    t_ok((int) scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE()
                        AND table_name = 'ai_conversation_history' AND index_name = 'idx_aich_conversation'") > 0, 'index exists');
});

t_run('each channel resolves with the page_id its own AI caller passes', function () use ($fb, $ig, $tg, $owa, $wac, $web) {
    t_eq(array('conversation_id' => $fb),  conversation_ai_history_link(LA, 'fb', 'PG99501', 'PSID-1'), 'fb: Facebook page id');
    t_eq(array('conversation_id' => $ig),  conversation_ai_history_link(LA, 'ig', 'PG99501', 'IGSID-1'), 'ig: Facebook page id');
    t_eq(array('conversation_id' => $tg),  conversation_ai_history_link(LA, 'tg', '99502', 'CHAT-1'), 'tg: account id');
    t_eq(array('conversation_id' => $owa), conversation_ai_history_link(LA, 'wa', 'sess-99503', '201000000001'), 'OpenWA: session id');
    t_eq(array('conversation_id' => $wac), conversation_ai_history_link(LA, 'wa', '99504', '201000000002'), 'WA Cloud: account id');
    t_eq(array('conversation_id' => $web), conversation_ai_history_link(LA, 'web', 'wk_any', 'web_sess_1'), 'web: widget key ignored, account 0');
});

t_run('never links across tenants, never invents a conversation', function () use ($fbB) {
    t_eq(array(), conversation_ai_history_link(LA, 'fb', 'PG99506', 'PSID-X'), "tenant A with tenant B's page and an unknown thread");
    t_eq(array('conversation_id' => $fbB), conversation_ai_history_link(LB, 'fb', 'PG99506', 'PSID-1'), "tenant B's own thread with the same PSID");
    t_ok(conversation_ai_history_link(LA, 'fb', 'PG99501', 'PSID-1') !== array('conversation_id' => $fbB), "tenant A never gets tenant B's id");
    $before = (int) scalar('SELECT COUNT(*) FROM conversations WHERE user_id IN (9951, 9952)');
    t_eq(array(), conversation_ai_history_link(LA, 'tg', '99502', 'NEVER-SEEN'), 'unknown thread -> nothing');
    t_eq(array(), conversation_ai_history_link(LA, 'fb', '', 'PSID-1'), 'comment reply without a page -> nothing');
    t_eq(array(), conversation_ai_history_link(0, 'fb', 'PG99501', 'PSID-1'), 'no tenant -> nothing');
    t_eq($before, (int) scalar('SELECT COUNT(*) FROM conversations WHERE user_id IN (9951, 9952)'), 'no conversation created');
});

t_run('unresolvable account: unique thread id is used, an ambiguous one is not', function () use ($fb) {
    // an IG turn whose page_id is not a Facebook page id we know: falls back to the only ig thread
    t_eq(array(), conversation_ai_history_link(LA, 'fb', 'UNKNOWN-PAGE', 'PSID-NOPE'), 'no thread at all -> nothing');
    t_eq(array('conversation_id' => $fb), conversation_ai_history_link(LA, 'fb', 'UNKNOWN-PAGE', 'PSID-1'), 'exactly one fb thread PSID-1 -> linked');
    // the same number on OpenWA and Cloud, with a session id that matches neither account
    ai_link_msg(LA, '201000000009', 99503, '', 'wa');
    ai_link_msg(LA, '201000000009', 99504, '', 'wa');
    t_eq(array(), conversation_ai_history_link(LA, 'wa', 'sess-unknown', '201000000009'), 'two wa threads -> ambiguous -> nothing');
});

t_run('a linked row is found by the inbox query (tenant + conversation, newest first)', function () use ($tg) {
    tdb()->query('DELETE FROM ai_conversation_history WHERE user_id = 9951');
    foreach (array('first answer', 'latest answer') as $i => $reply) {
        tdb()->insert('ai_conversation_history', array(
            'user_id' => LA, 'page_id' => '99502', 'subscribe_id' => 'CHAT-1', 'social_media' => 'tg',
            'human_message' => 'q' . $i, 'ai_reply' => $reply, 'created_at' => date('Y-m-d H:i:s'),
        ) + conversation_ai_history_link(LA, 'tg', '99502', 'CHAT-1'));
    }
    $row = rows('SELECT ai_reply FROM ai_conversation_history WHERE user_id = ? AND conversation_id = ? ORDER BY id DESC LIMIT 1', array(LA, $tg));
    t_eq('latest answer', $row[0]['ai_reply'] ?? null, 'latest AI turn for the conversation');
    tdb()->query('DELETE FROM ai_conversation_history WHERE user_id = 9951');
});

ai_link_cleanup();
echo "\n{$GLOBALS['__t']['pass']} passed, {$GLOBALS['__t']['fail']} failed\n";
exit($GLOBALS['__t']['fail'] === 0 ? 0 : 1);
