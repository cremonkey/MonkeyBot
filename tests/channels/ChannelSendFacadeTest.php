<?php
/**
 * channel_send_text() facade: same return values, same error strings, same
 * account lookup, same OpenWA-then-Cloud order and same livechat_messages rows
 * as the pre-9.0 helper, with the HTTP now going through the adapters.
 * Expected values are transcribed from the legacy helper.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use MonkeyBot\Channels\ChannelRegistry;
use MonkeyBot\Channels\HttpResponse;

require_once APPPATH . 'helpers/channel_send_helper.php';

/** Query-builder stand-in: records the chain, returns preset rows. */
final class StubDb
{
    public $calls = array();
    public $rows = array();
    public $tables = array();
    public function table_exists($t) { return in_array($t, $this->tables, true); }
    public function get() { $this->calls[] = array('get'); return $this; }
    public function result_array() { return $this->rows; }
    public function __call($name, $args) { $this->calls[] = array_merge(array($name), $args); return $this; }
}

final class StubBasic
{
    public $data = array();     // table => rows returned by get_data
    public $queries = array();  // [table, where]
    public $inserts = array();  // [table, row]
    public $throw_on_insert = null;
    public function get_data($table, $where = '', $select = '', $join = '', $limit = '')
    {
        $this->queries[] = array($table, $where, $limit);
        return isset($this->data[$table]) ? $this->data[$table] : array();
    }
    public function insert_data($table, $row)
    {
        if ($this->throw_on_insert) throw $this->throw_on_insert;
        $this->inserts[] = array($table, $row);
        return true;
    }
}

final class StubLoad
{
    public $loaded = array();
    public function helper($h) { $this->loaded[] = 'helper:' . (is_array($h) ? implode(',', $h) : $h); }
    public function library($l) { $this->loaded[] = 'library:' . $l; }
}

function facade_ci()
{
    $ci = get_instance();
    $ci->db = new StubDb();
    $ci->basic = new StubBasic();
    $ci->load = new StubLoad();
    return $ci;
}

function facade_transport($status = 200, $body = '{}')
{
    $t = new FakeTransport(new HttpResponse($status, is_string($body) ? $body : json_encode($body)));
    ChannelRegistry::useTransport($t);
    return $t;
}

function assert_log_row(array $insert, $platform, $subscriber, $text, $fb_page_id, $page_table_id)
{
    list($table, $row) = $insert;
    assert_same('livechat_messages', $table);
    $expected = array(
        'user_id' => 5, 'subscriber_id' => $subscriber,
        'page_table_id' => $page_table_id, 'fb_page_id' => $fb_page_id,
        'sender' => 'bot', 'platform' => $platform, 'agent_name' => 'Bot',
        'message_content' => $text, 'conversation_time' => $row['conversation_time'],
        'fb_message_id' => '', 'message_status' => 'sent', 'from_business_suite' => '0',
    );
    assert_same($expected, $row, 'livechat_messages row');
    assert_true((bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['conversation_time']));
}

// ---- fb / ig -----------------------------------------------------------------

t('facade fb: success sends the legacy request and logs the bot row', function () {
    $ci = facade_ci();
    $ci->db->rows = array(array('page_id' => '104857600000001', 'page_access_token' => 'PAGE_TOKEN'));
    $t = facade_transport(200, array('recipient_id' => '71', 'message_id' => 'm1'));
    $r = channel_send_text('5', 'fb', '71', "  مرحبا  ", '104857600000001');
    assert_same(array(true, ''), $r);
    $req = $t->only();
    assert_same('https://graph.facebook.com/v20.0/me/messages?access_token=PAGE_TOKEN', $req->url);
    assert_same('{"recipient":{"id":"71"},"messaging_type":"RESPONSE","message":{"text":"\u0645\u0631\u062d\u0628\u0627"}}', $req->body, 'text trimmed, JSON as json_encode() made it');
    assert_count(1, $ci->basic->inserts);
    assert_log_row($ci->basic->inserts[0], 'fb', '71', 'مرحبا', '104857600000001', 0);
    // same lookup chain as before: page_id OR numeric row id, scoped to the user
    assert_same(array(
        array('select', 'page_id, page_access_token'), array('from', 'facebook_rx_fb_page_info'), array('where', 'user_id', 5),
        array('group_start'), array('where', 'page_id', '104857600000001'), array('or_where', 'id', 104857600000001), array('group_end'),
        array('limit', 1), array('get'),
    ), $ci->db->calls);
});

t('facade ig: logs platform ig with the page id', function () {
    $ci = facade_ci();
    $ci->db->rows = array(array('page_id' => 'P1', 'page_access_token' => 'T'));
    facade_transport(200, array('message_id' => 'm'));
    assert_same(array(true, ''), channel_send_text(5, 'ig', 'IGSID', 'hi', ''));
    assert_log_row($ci->basic->inserts[0], 'ig', 'IGSID', 'hi', 'P1', 0);
    assert_same(array(array('select', 'page_id, page_access_token'), array('from', 'facebook_rx_fb_page_info'), array('where', 'user_id', 5), array('limit', 1), array('get')), $ci->db->calls, 'no page filter when page_id is empty');
});

t('facade fb: Graph error -> legacy error string, nothing logged', function () {
    $ci = facade_ci();
    $ci->db->rows = array(array('page_id' => 'P1', 'page_access_token' => 'T'));
    facade_transport(400, array('error' => array('message' => 'Error validating access token', 'code' => 190)));
    assert_same(array(false, 'graph: Error validating access token (code 190)'), channel_send_text(5, 'fb', '71', 'hi', 'P1'));
    assert_count(0, $ci->basic->inserts);
});

t('facade fb: LEGACY QUIRK preserved — network failure still reported as sent', function () {
    // Pre-9.0 code only failed when Graph returned an `error` object, so a dead
    // network looked like success. Kept on purpose; see adapters.md "Known legacy quirks".
    $ci = facade_ci();
    $ci->db->rows = array(array('page_id' => 'P1', 'page_access_token' => 'T'));
    ChannelRegistry::useTransport(FakeTransport::failing());
    assert_same(array(true, ''), channel_send_text(5, 'fb', '71', 'hi', 'P1'));
    assert_count(1, $ci->basic->inserts);
});

t('facade fb: no page token -> legacy error, no HTTP', function () {
    $ci = facade_ci();
    $ci->db->rows = array(array('page_id' => 'P1', 'page_access_token' => ''));
    $t = facade_transport();
    assert_same(array(false, 'page token not found'), channel_send_text(5, 'fb', '71', 'hi', 'P1'));
    assert_count(0, $t->requests);
});

// ---- wa ------------------------------------------------------------------------

t('facade wa: OpenWA account wins, api key decrypted, row logged with its id', function () {
    $ci = facade_ci();
    $ci->db->tables = array('openwa_accounts');
    $ci->basic->data['openwa_accounts'] = array(array('id' => '12', 'base_url' => 'https://wa.example.com', 'api_key' => 'enc::k', 'session_id' => 'main'));
    $ci->basic->data['whatsapp_accounts'] = array(array('id' => '3', 'access_token' => 'x', 'phone_number_id' => 'PN'));
    $t = facade_transport(201, array('id' => 'abc'));
    assert_same(array(true, ''), channel_send_text(5, 'wa', '01001234567', 'hi', '12'));
    $req = $t->only();
    assert_same('https://wa.example.com/api/sessions/main/messages/send-text', $req->url);
    assert_true(in_array('X-API-Key: dec(enc::k)', $req->headers, true), 'decrypted key sent');
    assert_same(array(array('openwa_accounts', array('where' => array('user_id' => 5, 'status' => '1', 'id' => 12)), 1)), $ci->basic->queries);
    assert_log_row($ci->basic->inserts[0], 'wa', '01001234567', 'hi', '', 12);
    assert_true(in_array('library:Openwa_api', $ci->load->loaded, true), 'library still loaded as before');
});

t('facade wa: non-numeric page_id selects the OpenWA session', function () {
    $ci = facade_ci();
    $ci->db->tables = array('openwa_accounts');
    $ci->basic->data['openwa_accounts'] = array(array('id' => '12', 'base_url' => 'https://wa.example.com', 'api_key' => 'k', 'session_id' => 'main'));
    facade_transport(200, array());
    channel_send_text(5, 'wa', '2010', 'hi', 'main');
    assert_same(array('user_id' => 5, 'status' => '1', 'session_id' => 'main'), $ci->basic->queries[0][1]['where']);
});

t('facade wa: OpenWA failure is final — no fall-through to Cloud', function () {
    $ci = facade_ci();
    $ci->db->tables = array('openwa_accounts');
    $ci->basic->data['openwa_accounts'] = array(array('id' => '12', 'base_url' => 'https://wa.example.com', 'api_key' => 'k', 'session_id' => 'main'));
    $ci->basic->data['whatsapp_accounts'] = array(array('id' => '3', 'access_token' => 'x', 'phone_number_id' => 'PN'));
    $t = facade_transport(503, array('error' => 'session not connected'));
    assert_same(array(false, 'openwa: "session not connected"'), channel_send_text(5, 'wa', '2010', 'hi'));
    assert_count(1, $t->requests, 'only the OpenWA request');
    assert_count(1, $ci->basic->queries, 'whatsapp_accounts never queried');
    assert_count(0, $ci->basic->inserts);
});

t('facade wa: no OpenWA account -> Cloud API, token decrypted', function () {
    $ci = facade_ci();
    $ci->db->tables = array('openwa_accounts');
    $ci->basic->data['whatsapp_accounts'] = array(array('id' => '3', 'access_token' => 'enc::t', 'phone_number_id' => 'PN'));
    $t = facade_transport(200, array('messages' => array(array('id' => 'wamid.1'))));
    assert_same(array(true, ''), channel_send_text(5, 'wa', '2010', 'hi', '3'));
    assert_same('https://graph.facebook.com/v21.0/PN/messages', $t->only()->url);
    assert_same(array('Content-Type: application/json', 'Authorization: Bearer dec(enc::t)'), $t->only()->headers);
    assert_same(array('whatsapp_accounts', array('where' => array('user_id' => 5, 'status' => '1', 'id' => 3)), 1), $ci->basic->queries[1]);
    assert_log_row($ci->basic->inserts[0], 'wa', '2010', 'hi', '', 3);
});

t('facade wa: no openwa_accounts table -> straight to Cloud', function () {
    $ci = facade_ci();
    $ci->basic->data['whatsapp_accounts'] = array(array('id' => '3', 'access_token' => 't', 'phone_number_id' => 'PN'));
    facade_transport(400, array('error' => array('message' => 'bad', 'code' => 100)));
    assert_same(array(false, 'wa: {"message":"bad","code":100}'), channel_send_text(5, 'wa', '2010', 'hi'));
    assert_same('whatsapp_accounts', $ci->basic->queries[0][0]);
});

t('facade wa: no account at all -> legacy error', function () {
    facade_ci();
    $t = facade_transport();
    assert_same(array(false, 'no active whatsapp/OpenWA account'), channel_send_text(5, 'wa', '2010', 'hi'));
    assert_count(0, $t->requests);
});

// ---- tg / web / others ----------------------------------------------------------

t('facade tg: success and legacy failure string', function () {
    $ci = facade_ci();
    $ci->basic->data['telegram_accounts'] = array(array('id' => '9', 'bot_token' => 'enc::b'));
    $t = facade_transport(200, array('ok' => true, 'result' => array('message_id' => 1)));
    assert_same(array(true, ''), channel_send_text(5, 'tg', '55', 'hi', '9'));
    assert_same('https://api.telegram.org/botdec(enc::b)/sendMessage', $t->only()->url);
    assert_log_row($ci->basic->inserts[0], 'tg', '55', 'hi', '', 9);

    $ci = facade_ci();
    $ci->basic->data['telegram_accounts'] = array(array('id' => '9', 'bot_token' => 'b'));
    facade_transport(403, array('ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'));
    assert_same(array(false, 'tg: {"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}'), channel_send_text(5, 'tg', '55', 'hi', '9'));
    assert_count(0, $ci->basic->inserts);
});

t('facade tg: no account -> legacy error', function () {
    facade_ci();
    assert_same(array(false, 'no active telegram account'), channel_send_text(5, 'tg', '55', 'hi'));
});

t('facade web: one livechat row, no HTTP', function () {
    $ci = facade_ci();
    $t = facade_transport();
    assert_same(array(true, ''), channel_send_text(5, 'web', 'web_abc', 'مرحبا', 'webchat'));
    assert_count(0, $t->requests);
    assert_count(1, $ci->basic->inserts, 'exactly one row (no double logging)');
    assert_log_row($ci->basic->inserts[0], 'web', 'web_abc', 'مرحبا', '', 0);
});

t('facade web: insert exception -> [false, message] and error logged, as before', function () {
    $ci = facade_ci();
    $ci->basic->throw_on_insert = new \Exception('Table livechat_messages is full');
    assert_same(array(false, 'Table livechat_messages is full'), channel_send_text(5, 'web', 'web_abc', 'x'));
    assert_same(array('error', 'channel_send_text web: Table livechat_messages is full'), $GLOBALS['__log'][0]);
});

t('facade: unchanged guards for empty input and unknown channels', function () {
    facade_ci();
    $t = facade_transport();
    assert_same(array(false, 'empty message or recipient'), channel_send_text(5, 'fb', '71', '   '));
    assert_same(array(false, 'empty message or recipient'), channel_send_text(5, 'fb', '', 'hi'));
    assert_same(array(false, 'unsupported channel: tiktok'), channel_send_text(5, 'tiktok', 'u', 'hi'));
    assert_same(array(false, 'unsupported channel: sms'), channel_send_text(5, 'sms', 'u', 'hi'));
    assert_count(0, $t->requests);
});

t('facade: legacy function names still exist', function () {
    foreach (array('channel_send_text', 'channel_send_fb_ig', 'channel_send_wa', 'channel_send_tg', 'channel_send_web', 'channel_log_message') as $fn) {
        assert_true(function_exists($fn), $fn);
    }
});
