<?php
/**
 * cpr_flush() against a real, disposable MariaDB (initial_db.sql + every migration, so the
 * livechat -> conversation link trigger and internal_notes exist). Uses the conversation-core
 * test bootstrap (CodeIgniter's real query builder + mysqli driver, no app boot).
 * Proves: the logged private reply creates the fb/ig conversation through the trigger, keyed by
 * the PSID; a later DM from the same person lands in the SAME conversation; retries do not
 * duplicate; the internal note is attached; a DB error never escapes.
 *
 *   docker exec -e MBCONV_HOST=db -e MBCONV_USER=... -e MBCONV_PW=... -e MBCONV_DB=..._test \
 *     <app> php /var/www/html/tests/comment_private_reply/db_flush.php
 *
 * Tenant 9961, page rows 99611 (fb) / 99612 (ig) are created and removed here.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../conversations/bootstrap.php';
require_once APPPATH . 'helpers/comment_private_reply_helper.php';

const CT = 9961;

class DbFakeFb { public $calls = 0; public function get_private_reply_message_id_info($mid, $tok) { $this->calls++; return array('to' => array(array('id' => 'IGSID_LOOKED_UP'))); } }

function cpr_db_cleanup()
{
    foreach (array(
        'DELETE FROM internal_notes WHERE user_id = 9961',
        'DELETE FROM conversation_events WHERE user_id = 9961',
        'DELETE FROM conversations WHERE user_id = 9961',
        'DELETE FROM customer_identities WHERE user_id = 9961',
        'DELETE FROM customers WHERE user_id = 9961',
        "DELETE FROM livechat_messages WHERE user_id = 9961 OR fb_page_id IN ('PG99611')",
        'DELETE FROM facebook_rx_fb_page_info WHERE id IN (99611, 99612)',
    ) as $sql) tdb()->query($sql);
}

function cpr_ctx($platform, $acct, $extra = array())
{
    return array_merge(array('platform' => $platform, 'user_id' => CT, 'page_table_id' => $acct, 'fb_page_id' => 'PG99611',
        'token' => 'tok', 'fallback_psid' => '',
        'report' => array('comment_id' => 'C1', 'comment_text' => 'how much?', 'comment_reply_text' => 'Sent you a DM!', 'reply_status_comment' => 'success')), $extra);
}

cpr_db_cleanup();
tdb()->query("INSERT INTO facebook_rx_fb_page_info (id,user_id,facebook_rx_fb_user_info_id,page_id,page_name,page_access_token,has_instagram,bot_enabled)
              VALUES (99611," . CT . ",0,'PG99611','Shop page','t',1,'1')");
$ci = get_instance();
$ci->fb_rx_login = new DbFakeFb();

t_run('fb: private reply creates the conversation (trigger), keyed by the PSID', function () use ($ci) {
    cpr_buffer('take');
    cpr_buffer_capture(array('recipient' => array('comment_id' => 'C1'), 'message' => array('text' => 'Hi! The price is 100.')),
        array('recipient_id' => 'PSID_FB_1', 'message_id' => 'm_fb_1'));
    t_eq(1, cpr_flush($ci, cpr_ctx('fb', 99611)), 'one row written');
    $r = rows("SELECT * FROM livechat_messages WHERE fb_message_id = 'm_fb_1'");
    t_eq(1, count($r), 'row exists');
    t_eq('bot', $r[0]['sender'], 'sender');
    t_eq('PSID_FB_1', $r[0]['subscriber_id'], 'subscriber_id');
    t_ok((int) $r[0]['conversation_id'] > 0, 'trigger linked a conversation');
    $c = rows('SELECT * FROM conversations WHERE id = ?', array((int) $r[0]['conversation_id']))[0];
    t_eq('fb', $c['platform'], 'conversation platform');
    t_eq('99611', (string) $c['channel_account_id'], 'channel account = page row');
    t_eq('PSID_FB_1', $c['external_thread_id'], 'thread = PSID');
    t_eq('0', (string) $c['unread_count'], 'bot message is not unread');
    t_eq(null, $c['last_inbound_at'], 'no inbound yet (customer only commented)');
    $n = rows('SELECT * FROM internal_notes WHERE conversation_id = ?', array((int) $c['id']));
    t_eq(1, count($n), 'one internal note');
    t_ok(strpos($n[0]['note'], 'Comment: "how much?"') !== false && strpos($n[0]['note'], 'Public reply: "Sent you a DM!"') !== false, 'note has comment + public reply');
    t_eq('Comment auto-reply', $n[0]['author_name'], 'note author');
    t_eq(0, $ci->fb_rx_login->calls, 'no Graph lookup needed');
});

t_run('fb: webhook retry (same message id) writes nothing new', function () use ($ci) {
    cpr_buffer_capture(array('recipient' => array('comment_id' => 'C1'), 'message' => array('text' => 'Hi! The price is 100.')),
        array('recipient_id' => 'PSID_FB_1', 'message_id' => 'm_fb_1'));
    t_eq(0, cpr_flush($ci, cpr_ctx('fb', 99611)), 'nothing written');
    t_eq('1', (string) scalar("SELECT COUNT(*) FROM livechat_messages WHERE fb_message_id = 'm_fb_1'"), 'still one row');
    t_eq('1', (string) scalar('SELECT COUNT(*) FROM internal_notes WHERE user_id = ' . CT), 'still one note');
    t_eq('1', (string) scalar("SELECT message_count FROM conversations WHERE user_id = " . CT . " AND external_thread_id = 'PSID_FB_1'"), 'message_count not bumped');
});

t_run('fb: the customer answering in Messenger joins the same conversation', function () {
    $ok = tdb()->insert('livechat_messages', array('user_id' => CT, 'subscriber_id' => 'PSID_FB_1', 'page_table_id' => 99611,
        'fb_page_id' => 'PG99611', 'sender' => 'user', 'platform' => 'fb', 'message_content' => 'ok thanks',
        'conversation_time' => date('Y-m-d H:i:s'), 'fb_message_id' => 'm_in_1', 'message_status' => 'read', 'from_business_suite' => '0'));
    t_ok($ok, 'inbound insert');
    t_eq('1', (string) scalar("SELECT COUNT(*) FROM conversations WHERE user_id = " . CT . " AND platform = 'fb'"), 'one fb conversation');
    $c = rows("SELECT * FROM conversations WHERE user_id = " . CT . " AND platform = 'fb'")[0];
    t_eq('2', (string) $c['message_count'], 'two messages');
    t_eq('1', (string) $c['unread_count'], 'inbound is unread');
    t_ok($c['last_inbound_at'] !== null, 'inbound window opened');
});

t_run('fb: two-message template, PSID from the caller lookup', function () use ($ci) {
    cpr_buffer_capture(json_encode(array('recipient' => array('comment_id' => 'C2'), 'message' => array('text' => 'part 1'))), array('message_id' => 'm_fb_2a'));
    cpr_buffer_capture(json_encode(array('recipient' => array('comment_id' => 'C2'), 'message' => array('attachment' => array('type' => 'template', 'payload' => array('template_type' => 'button', 'text' => 'Choose', 'buttons' => array()))))), array('message_id' => 'm_fb_2b'));
    $ctx = cpr_ctx('fb', 99611, array('fallback_psid' => 'PSID_FB_2'));
    $ctx['report']['comment_id'] = 'C2';
    t_eq(2, cpr_flush($ci, $ctx), 'two rows');
    t_eq('2', (string) scalar("SELECT message_count FROM conversations WHERE user_id = " . CT . " AND external_thread_id = 'PSID_FB_2'"), 'both on one conversation');
});

t_run('ig: no recipient_id, no caller PSID -> lookup, ig conversation', function () use ($ci) {
    tdb()->query("INSERT INTO facebook_rx_fb_page_info (id,user_id,facebook_rx_fb_user_info_id,page_id,page_name,page_access_token,has_instagram,bot_enabled)
                  VALUES (99612," . CT . ",0,'PG99612','IG page','t',1,'1')");
    cpr_buffer_capture(json_encode(array('recipient' => array('comment_id' => '17890001'), 'message' => array('text' => 'AI answer'), 'tag' => 'HUMAN_AGENT')), array('message_id' => 'm_ig_1'));
    $ctx = cpr_ctx('ig', 99612, array('fb_page_id' => 'PG99612'));
    $ctx['report']['comment_id'] = '17890001';
    t_eq(1, cpr_flush($ci, $ctx), 'one row');
    $c = rows("SELECT * FROM conversations WHERE user_id = " . CT . " AND platform = 'ig'");
    t_eq(1, count($c), 'ig conversation');
    t_eq('IGSID_LOOKED_UP', $c[0]['external_thread_id'], 'IGSID from the lookup');
    t_eq('99612', (string) $c[0]['channel_account_id'], 'ig account = page row');
});

t_run('a SQL error never escapes and db_debug is restored', function () use ($ci) {
    tdb()->query('ALTER TABLE livechat_messages RENAME TO livechat_messages_hidden');
    try {
        cpr_buffer_capture(array('recipient' => array('comment_id' => 'C9'), 'message' => array('text' => 'x')), array('recipient_id' => 'P9', 'message_id' => 'm_err'));
        $before = tdb()->db_debug;
        t_eq(0, cpr_flush($ci, cpr_ctx('fb', 99611)), 'nothing written, no exception');
        t_eq($before, tdb()->db_debug, 'db_debug restored');
    } finally {
        tdb()->query('ALTER TABLE livechat_messages_hidden RENAME TO livechat_messages');
    }
});

cpr_db_cleanup();
echo "\n{$GLOBALS['__t']['pass']} passed, {$GLOBALS['__t']['fail']} failed\n";
exit($GLOBALS['__t']['fail'] === 0 ? 0 : 1);
