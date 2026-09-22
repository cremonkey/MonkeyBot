<?php
/**
 * CurlTransport must set exactly the curl options each legacy client set.
 * The expected arrays are transcribed from the pre-9.0 code:
 *   Graph send   — channel_send_fb_ig() curl_setopt_array block
 *   OpenWA       — Openwa_api::request(), POST branch
 *   WA Cloud     — Whatsapp_api::post()
 *   Telegram     — Telegram_api::call()
 * (RETURNTRANSFER/POST were set as 1 in the libraries and true in the helper;
 * curl treats both identically, the comparison normalises them.)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use MonkeyBot\Channels\ChannelRegistry;
use MonkeyBot\Channels\CurlTransport;
use MonkeyBot\Channels\OutboundMessage;

function normalise_curl_opts(array $o)
{
    foreach (array(CURLOPT_RETURNTRANSFER, CURLOPT_POST, CURLOPT_SSL_VERIFYPEER) as $k) {
        if (isset($o[$k])) $o[$k] = (bool) $o[$k];
    }
    ksort($o);
    return $o;
}

/** Build the request through the adapter (FakeTransport), then ask CurlTransport what it would set. */
function curl_opts_for($key, array $account, $recipient, $text)
{
    $t = new FakeTransport(new \MonkeyBot\Channels\HttpResponse(200, '{"messages":[{"id":"x"}],"ok":true}'));
    ChannelRegistry::for($key, $t)->sendText(OutboundMessage::text($recipient, $text, $account));
    return normalise_curl_opts((new CurlTransport())->curlOptions($t->only()));
}

t('curl: Graph send uses the legacy option set', function () {
    $payload = json_encode(array('recipient' => array('id' => '71'), 'messaging_type' => 'RESPONSE', 'message' => array('text' => 'hi')));
    $legacy = array(
        CURLOPT_URL            => graph_url('me/messages?access_token=', TRUE) . urlencode('tok'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    );
    foreach (array('fb', 'ig') as $key) {
        assert_same(normalise_curl_opts($legacy), curl_opts_for($key, array('page_access_token' => 'tok'), '71', 'hi'), $key);
    }
});

t('curl: OpenWA send uses the legacy option set', function () {
    $legacy = array(
        CURLOPT_URL            => 'https://wa.example.com/api/sessions/s1/messages/send-text',
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json', 'X-API-Key: k'),
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => json_encode(array('chatId' => '201001234567@c.us', 'text' => 'hi')),
    );
    assert_same(normalise_curl_opts($legacy),
        curl_opts_for('wa_openwa', array('base_url' => 'https://wa.example.com', 'api_key' => 'k', 'session_id' => 's1'), '201001234567', 'hi'));
});

t('curl: WhatsApp Cloud send uses the legacy option set', function () {
    $legacy = array(
        CURLOPT_URL            => 'https://graph.facebook.com/v21.0/PN1/messages',
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => json_encode(array('messaging_product' => 'whatsapp', 'to' => '2010', 'type' => 'text',
                                                    'text' => array('preview_url' => true, 'body' => 'hi'))),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Authorization: Bearer T'),
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    );
    assert_same(normalise_curl_opts($legacy), curl_opts_for('wa_cloud', array('access_token' => 'T', 'phone_number_id' => 'PN1'), '2010', 'hi'));
});

t('curl: Telegram send uses the legacy option set', function () {
    $legacy = array(
        CURLOPT_URL            => 'https://api.telegram.org/botB:T/sendMessage',
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => http_build_query(array('chat_id' => '55', 'text' => 'hi')),
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    );
    assert_same(normalise_curl_opts($legacy), curl_opts_for('tg', array('bot_token' => 'B:T'), '55', 'hi'));
});

t('curl: an unreachable host comes back as a transport failure, not an exception', function () {
    // 127.0.0.1:9 (discard) is closed in the test container: connection refused, no real network used
    $res = (new CurlTransport())->send(new \MonkeyBot\Channels\HttpRequest('POST', 'http://127.0.0.1:9/x', null, 'a=b', 2, 1, true, null));
    assert_true($res->transportFailed());
    assert_same(0, $res->status);
    assert_true($res->error !== '');
});
