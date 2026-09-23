<?php
/**
 * Common behaviour suite every channel adapter runs (brief §44).
 *
 * The expected requests below are transcribed from the legacy senders
 * (application/helpers/channel_send_helper.php before Phase 9.0,
 * libraries/Openwa_api.php, Whatsapp_api.php, Telegram_api.php) — URL, headers,
 * body, timeouts and TLS options must match them exactly.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use MonkeyBot\Channels\Capability;
use MonkeyBot\Channels\ChannelRegistry;
use MonkeyBot\Channels\HttpRequest;
use MonkeyBot\Channels\HttpResponse;
use MonkeyBot\Channels\InboundMessage;
use MonkeyBot\Channels\OutboundMessage;
use MonkeyBot\Channels\SendResult;

const AR_TEXT = 'مرحبا، عايز أعرف سعر الغرفة 🙏';

/**
 * Per-adapter contract spec.
 *  account      credentials for OutboundMessage
 *  recipient    recipient as legacy callers pass it
 *  request      fn(string $text): HttpRequest   the legacy request for a text send (null = no HTTP)
 *  text_of      fn(HttpRequest): string          pulls the message text back out of a request body
 *  ok_response  [status, body] a successful channel reply, and ok_id the id expected from it
 *  channel_error [status, body, exact legacy error string, retryable]
 *  transport_error exact legacy error string when the network fails
 *  inbound      fixture => expectations
 */
function adapter_specs()
{
    $specs = array();

    $meta = function ($key, $page_id, $sender, $text_mid, $img_mid, $text_fixture, $img_fixture) {
        return array(
            'account'   => array('page_access_token' => 'EAAG+tok/en=='),
            'recipient' => $sender,
            'request'   => function ($text) use ($sender) {
                return new HttpRequest(
                    'POST',
                    'https://graph.facebook.com/v20.0/me/messages?access_token=' . urlencode('EAAG+tok/en=='),
                    array('Content-Type: application/json'),
                    json_encode(array('recipient' => array('id' => $sender), 'messaging_type' => 'RESPONSE', 'message' => array('text' => $text))),
                    20, null, true, null
                );
            },
            'text_of' => function (HttpRequest $r) { return json_decode($r->body, true)['message']['text']; },
            'ok_response' => array(200, array('recipient_id' => $sender, 'message_id' => 'm_sent_1')),
            'ok_id' => 'm_sent_1',
            'channel_error' => array(400, array('error' => array('message' => '(#100) No matching user found', 'type' => 'OAuthException', 'code' => 100, 'fbtrace_id' => 'Ax1')),
                                     'graph: (#100) No matching user found (code 100)', false),
            'rate_error' => array(400, array('error' => array('message' => '(#613) Calls to this api have exceeded the rate limit.', 'code' => 613))),
            'transport_error' => null, // legacy had no wording: it treated a dead network as success (see facade test)
            'inbound' => array(
                $text_fixture => array('text' => AR_TEXT, 'id' => $text_mid, 'subscriber' => $sender, 'account' => $page_id, 'attachments' => 0),
                $img_fixture  => array('text' => '', 'id' => $img_mid, 'subscriber' => $sender, 'account' => $page_id, 'attachments' => 1, 'attachment_type' => 'image'),
            ),
        );
    };

    $meta_image = function ($sender) {
        return new HttpRequest(
            'POST',
            'https://graph.facebook.com/v20.0/me/messages?access_token=' . urlencode('EAAG+tok/en=='),
            array('Content-Type: application/json'),
            json_encode(array('recipient' => array('id' => $sender), 'messaging_type' => 'RESPONSE',
                'message' => array('attachment' => array('type' => 'image', 'payload' => array('url' => 'https://cdn.example.com/menu.jpg', 'is_reusable' => false))))),
            20, null, true, null
        );
    };

    $specs['fb'] = $meta('fb', '104857600000001', '7101234567890123', 'm_AbCdEf123-fb-text', 'm_AbCdEf456-fb-image', 'fb_text', 'fb_image');
    $specs['fb']['image_request'] = $meta_image('7101234567890123');
    $specs['fb']['image_caption'] = false; // Send API image attachments have no caption
    $specs['fb']['inbound']['fb_text']['reply_to'] = 'm_prev-fb-1';
    $specs['ig'] = $meta('ig', '17841400000000001', '1234567890123456', 'aWdfZAG1faXRlbToxOklHTWVzc2FnZAUlEOjE3ODQxNDAw', 'aWdfZAG1faXRlbToxOklHTWVzc2FnZAUlEOjIMAGE', 'ig_text', 'ig_image');
    $specs['ig']['image_request'] = $meta_image('1234567890123456');
    $specs['ig']['image_caption'] = false;

    $owa_headers = array('Content-Type: application/json', 'Accept: application/json', 'X-API-Key: owa-key-1');
    $specs['wa_openwa'] = array(
        'account'   => array('base_url' => 'https://wa.example.com/', 'api_key' => 'owa-key-1', 'session_id' => 'monkey main'),
        'recipient' => '01001234567', // Egyptian local number: Openwa_api::to_chat_id() -> 201001234567@c.us
        'request'   => function ($text) use ($owa_headers) {
            return new HttpRequest('POST', 'https://wa.example.com/api/sessions/monkey%20main/messages/send-text', $owa_headers,
                json_encode(array('chatId' => '201001234567@c.us', 'text' => $text)), 15, 5, true, 2);
        },
        'image_request' => new HttpRequest('POST', 'https://wa.example.com/api/sessions/monkey%20main/messages/send-image', $owa_headers,
                json_encode(array('chatId' => '201001234567@c.us', 'url' => 'https://cdn.example.com/menu.jpg', 'caption' => 'المنيو')), 15, 5, true, 2),
        'text_of' => function (HttpRequest $r) { return json_decode($r->body, true)['text']; },
        'ok_response' => array(201, array('id' => 'true_201001234567@c.us_3EB0AA', 'timestamp' => 1758542400)),
        'ok_id' => 'true_201001234567@c.us_3EB0AA',
        'channel_error' => array(401, array('statusCode' => 401, 'error' => 'Unauthorized', 'message' => 'Invalid API key'),
                                 'openwa: "Unauthorized"', false),
        'rate_error' => array(429, array('statusCode' => 429, 'error' => 'Too Many Requests')),
        'transport_error' => 'openwa: {"_http":0,"_error":"Connection timed out after 15001 milliseconds"}',
        'inbound' => array(
            'openwa_text'  => array('text' => AR_TEXT, 'id' => '3EB0C431D8F6A1B2C3D4', 'subscriber' => '201001234567', 'account' => '9', 'attachments' => 0, 'name' => 'أحمد'),
            'openwa_image' => array('text' => 'شوف الصورة دي', 'id' => '3EB0C431D8F6A1B2IMG1', 'subscriber' => '123456789012345', 'account' => '9', 'attachments' => 1, 'attachment_type' => 'image'),
            'openwa_fromme' => null,
        ),
    );

    $specs['wa_cloud'] = array(
        'account'   => array('access_token' => 'EAAW-cloud-token', 'phone_number_id' => '106540352242922'),
        'recipient' => '201001234567',
        'request'   => function ($text) {
            return new HttpRequest('POST', 'https://graph.facebook.com/v21.0/106540352242922/messages',
                array('Content-Type: application/json', 'Authorization: Bearer EAAW-cloud-token'),
                json_encode(array('messaging_product' => 'whatsapp', 'to' => '201001234567', 'type' => 'text',
                                  'text' => array('preview_url' => true, 'body' => $text))),
                30, null, true, 2);
        },
        'image_request' => new HttpRequest('POST', 'https://graph.facebook.com/v21.0/106540352242922/messages',
                array('Content-Type: application/json', 'Authorization: Bearer EAAW-cloud-token'),
                json_encode(array('messaging_product' => 'whatsapp', 'to' => '201001234567', 'type' => 'image',
                                  'image' => array('link' => 'https://cdn.example.com/menu.jpg', 'caption' => 'المنيو'))),
                30, null, true, 2),
        'text_of' => function (HttpRequest $r) { return json_decode($r->body, true)['text']['body']; },
        'ok_response' => array(200, array('messaging_product' => 'whatsapp', 'contacts' => array(array('input' => '201001234567', 'wa_id' => '201001234567')),
                                          'messages' => array(array('id' => 'wamid.SENT1')))),
        'ok_id' => 'wamid.SENT1',
        'channel_error' => array(400, array('error' => array('message' => '(#131030) Recipient phone number not in allowed list', 'type' => 'OAuthException', 'code' => 131030)),
                                 'wa: {"message":"(#131030) Recipient phone number not in allowed list","type":"OAuthException","code":131030}', false),
        'rate_error' => array(400, array('error' => array('message' => '(#130429) Rate limit hit', 'code' => 130429))),
        'transport_error' => 'wa: null',
        'inbound' => array(
            'wa_cloud_text'  => array('text' => AR_TEXT, 'id' => 'wamid.HBgMMjAxMDAxMjM0NTY3FQIAEhggQTEText', 'subscriber' => '201001234567', 'account' => '106540352242922', 'attachments' => 0, 'name' => 'Mona', 'reply_to' => 'wamid.PREVIOUS'),
            'wa_cloud_image' => array('text' => 'الفاتورة', 'id' => 'wamid.HBgMMjAxMDAxMjM0NTY3FQIAEhggImage', 'subscriber' => '201001234567', 'account' => '106540352242922', 'attachments' => 1, 'attachment_type' => 'image'),
            'wa_cloud_status' => null,
        ),
    );

    $specs['tg'] = array(
        'account'   => array('bot_token' => '123456:AAH-bot-token'),
        'recipient' => '55501234',
        'request'   => function ($text) {
            return new HttpRequest('POST', 'https://api.telegram.org/bot123456:AAH-bot-token/sendMessage', null,
                http_build_query(array('chat_id' => '55501234', 'text' => $text)), 30, null, true, 2);
        },
        'image_request' => new HttpRequest('POST', 'https://api.telegram.org/bot123456:AAH-bot-token/sendPhoto', null,
                http_build_query(array('chat_id' => '55501234', 'photo' => 'https://cdn.example.com/menu.jpg', 'caption' => 'المنيو')), 30, null, true, 2),
        'text_of' => function (HttpRequest $r) { parse_str($r->body, $p); return $p['text']; },
        'ok_response' => array(200, array('ok' => true, 'result' => array('message_id' => 4300, 'chat' => array('id' => 55501234), 'text' => 'x'))),
        'ok_id' => '4300',
        'channel_error' => array(400, array('ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'),
                                 'tg: {"ok":false,"error_code":400,"description":"Bad Request: chat not found"}', false),
        'rate_error' => array(429, array('ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 5', 'parameters' => array('retry_after' => 5))),
        'transport_error' => 'tg: null',
        'inbound' => array(
            'tg_text'  => array('text' => AR_TEXT, 'id' => '55501234:4211', 'subscriber' => '55501234', 'account' => '9', 'attachments' => 0, 'name' => 'Omar Ali', 'reply_to' => '55501234:4209'),
            'tg_photo' => array('text' => 'صورة الإيصال', 'id' => '55501234:4212', 'subscriber' => '55501234', 'account' => '9', 'attachments' => 1, 'attachment_type' => 'image'),
            'tg_edited' => array('text' => 'edited text', 'id' => '55501234:4211:e1758542500', 'subscriber' => '55501234', 'account' => '9', 'attachments' => 0),
        ),
    );

    $specs['web'] = array(
        'account'   => array(),
        'recipient' => 'web_0123456789abcdef01234567',
        'request'   => null, // pull-based: delivery is the outbox row, no HTTP
        'inbound'   => array(
            'web_text' => array('text' => AR_TEXT, 'id' => 'c-1758542400-1', 'subscriber' => 'web_0123456789abcdef01234567', 'account' => 'wk_3f9a1c2b7d', 'attachments' => 0),
        ),
    );

    $specs['tiktok'] = array('account' => array(), 'recipient' => 'tt-user', 'request' => null, 'inbound' => array());

    return $specs;
}

function all_fixture_names()
{
    $n = array();
    foreach (glob(__DIR__ . '/fixtures/*.json') as $f) $n[] = basename($f, '.json');
    return $n;
}

function assert_request_equals(HttpRequest $expected, HttpRequest $actual)
{
    foreach (array('method', 'url', 'headers', 'body', 'timeout', 'connect_timeout', 'verify_peer', 'verify_host') as $f) {
        assert_same($expected->$f, $actual->$f, "request.$f differs from the legacy request");
    }
}

function adapter_with($key, FakeTransport $t)
{
    $a = ChannelRegistry::for($key, $t);
    if ($key === 'web') {
        $a = $a->withOutbox(function (OutboundMessage $m) { $GLOBALS['__outbox'][] = $m; });
    }
    return $a;
}

function malformed_payloads()
{
    return array(
        array(),
        array('foo' => 'bar'),
        array('object' => 'page', 'entry' => 'not-a-list'),
        array('object' => 'page', 'entry' => array('x', 1, null, array('messaging' => 'nope'), array('messaging' => array(array('sender' => 'x'))))),
        array('object' => 'instagram', 'entry' => array(array('messaging' => array(array('sender' => array('id' => array()), 'message' => 'str'))))),
        array('object' => 'whatsapp_business_account', 'entry' => array(array('changes' => array(array('value' => array('messages' => array('x', array('from' => array(1))))))))),
        array('update_id' => 1, 'message' => 'text'),
        array('update_id' => 1, 'message' => array('chat' => array('id' => array()))),
        array('event' => 'session.status', 'data' => array('status' => 'CONNECTED')),
        array('event' => array('x'), 'data' => 'x'),
        array('widget_key' => array('x'), 'message' => array('y')),
        array('widget_key' => 'wk', 'message' => '   '),
        array('entry' => array(array('messaging' => array(array('sender' => array('id' => '1'), 'message' => array('mid' => 'm', 'text' => array('nested')))))))
    );
}

// ---------------------------------------------------------------------------

foreach (adapter_specs() as $key => $spec) {

    t("$key: registry key, capabilities are known names, supports() agrees", function () use ($key) {
        $a = ChannelRegistry::for($key, new FakeTransport());
        assert_same($key, $a->key());
        foreach ($a->capabilities() as $cap) {
            assert_true(in_array($cap, Capability::all(), true), "unknown capability '$cap'");
            assert_true($a->supports($cap), "supports('$cap') false although declared");
        }
        foreach (array_diff(Capability::all(), $a->capabilities()) as $cap) {
            assert_false($a->supports($cap), "supports('$cap') true although not declared");
        }
        assert_same(count(array_unique($a->capabilities())), count($a->capabilities()), 'duplicate capabilities');
    });

    t("$key: send text", function () use ($key, $spec) {
        $t = isset($spec['ok_response']) ? FakeTransport::replying($spec['ok_response'][0], $spec['ok_response'][1]) : new FakeTransport();
        $GLOBALS['__outbox'] = array();
        $a = adapter_with($key, $t);
        $r = $a->sendText(OutboundMessage::text($spec['recipient'], 'hello there', $spec['account'], 7));
        if (!$a->supports(Capability::SEND_TEXT)) {
            assert_false($r->ok, 'unsupported send must not report ok');
            assert_true($r->unsupported, 'must be flagged unsupported');
            assert_false($r->retryable, 'unsupported is not retryable');
            assert_count(0, $t->requests, 'no HTTP for an unsupported send');
            return;
        }
        assert_true($r->ok, 'send failed: ' . $r->error);
        assert_false($r->unsupported);
        if ($spec['request'] === null) {
            assert_count(0, $t->requests, 'pull channel makes no HTTP call');
            assert_count(1, $GLOBALS['__outbox'], 'outbox rows');
            assert_same('hello there', $GLOBALS['__outbox'][0]->text);
            assert_same($spec['recipient'], $GLOBALS['__outbox'][0]->recipient);
        } else {
            assert_request_equals($spec['request']('hello there'), $t->only());
            assert_same($spec['ok_id'], $r->external_message_id, 'external message id');
        }
    });

    t("$key: Arabic text round-trips unchanged (outbound)", function () use ($key, $spec) {
        $a0 = ChannelRegistry::for($key, new FakeTransport());
        if (!$a0->supports(Capability::SEND_TEXT)) skip('no send_text');
        $t = isset($spec['ok_response']) ? FakeTransport::replying($spec['ok_response'][0], $spec['ok_response'][1]) : new FakeTransport();
        $GLOBALS['__outbox'] = array();
        $r = adapter_with($key, $t)->sendText(OutboundMessage::text($spec['recipient'], AR_TEXT, $spec['account']));
        assert_true($r->ok, 'send failed: ' . $r->error);
        if ($spec['request'] === null) {
            assert_same(AR_TEXT, $GLOBALS['__outbox'][0]->text);
        } else {
            assert_request_equals($spec['request'](AR_TEXT), $t->only());
            assert_same(AR_TEXT, $spec['text_of']($t->only()));
        }
    });

    t("$key: unsupported media returns unsupported, never success", function () use ($key, $spec) {
        $t = new FakeTransport();
        $a = adapter_with($key, $t);
        $checked = 0;
        foreach (array('image' => Capability::SEND_IMAGE, 'video' => Capability::SEND_MEDIA, 'file' => Capability::SEND_MEDIA) as $type => $cap) {
            if ($a->supports($cap)) continue;
            $r = $a->sendMedia(OutboundMessage::media($spec['recipient'], $type, 'https://cdn.example.com/x', '', $spec['account']));
            assert_false($r->ok, "$type: must not be ok");
            assert_true($r->unsupported, "$type: must be flagged unsupported");
            assert_false($r->retryable, "$type: unsupported is not retryable");
            assert_null($r->external_message_id);
            $checked++;
        }
        assert_count(0, $t->requests, 'no HTTP for unsupported sends');
        if ($checked === 0) skip('adapter supports every media type');
    });

    t("$key: outbound transport failure -> ok=false, error, retryable", function () use ($key, $spec) {
        $a0 = ChannelRegistry::for($key, new FakeTransport());
        if (!$a0->supports(Capability::SEND_TEXT)) skip('no send_text');
        if ($key === 'web') {
            $a = ChannelRegistry::for('web')->withOutbox(function () { throw new \RuntimeException('Lock wait timeout exceeded'); });
            $r = $a->sendText(OutboundMessage::text($spec['recipient'], 'x'));
            assert_false($r->ok);
            assert_true($r->retryable);
            assert_same('Lock wait timeout exceeded', $r->error);
            return;
        }
        $t = FakeTransport::failing();
        $r = adapter_with($key, $t)->sendText(OutboundMessage::text($spec['recipient'], 'x', $spec['account']));
        assert_false($r->ok, 'network failure must not be ok');
        assert_true($r->retryable, 'network failure is retryable');
        assert_true(is_string($r->error) && $r->error !== '', 'error text required');
        assert_same(0, $r->http_status);
        if ($spec['transport_error'] !== null) assert_same($spec['transport_error'], $r->error, 'legacy error wording');
    });

    t("$key: outbound HTTP 5xx -> ok=false, retryable", function () use ($key, $spec) {
        if ($spec['request'] === null) skip('no HTTP on this channel');
        $t = FakeTransport::replying(502, '<html><body>502 Bad Gateway</body></html>');
        $r = adapter_with($key, $t)->sendText(OutboundMessage::text($spec['recipient'], 'x', $spec['account']));
        assert_false($r->ok);
        assert_true($r->retryable, '5xx is retryable');
        assert_same(502, $r->http_status);
        assert_true($r->error !== '');
    });

    t("$key: channel rejection -> ok=false, exact legacy error, not retryable", function () use ($key, $spec) {
        if (!isset($spec['channel_error'])) skip('no HTTP on this channel');
        list($status, $body, $legacy, $retry) = $spec['channel_error'];
        $r = adapter_with($key, FakeTransport::replying($status, $body))->sendText(OutboundMessage::text($spec['recipient'], 'x', $spec['account']));
        assert_false($r->ok);
        assert_same($legacy, $r->error, 'legacy error wording');
        assert_same($retry, $r->retryable);
    });

    t("$key: rate limit -> retryable", function () use ($key, $spec) {
        if (!isset($spec['rate_error'])) skip('channel has no rate-limit reply to model');
        list($status, $body) = $spec['rate_error'];
        $r = adapter_with($key, FakeTransport::replying($status, $body))->sendText(OutboundMessage::text($spec['recipient'], 'x', $spec['account']));
        assert_false($r->ok);
        assert_true($r->retryable, 'rate limit must be retryable');
    });

    t("$key: missing credentials -> no request, not ok", function () use ($key, $spec) {
        if ($spec['request'] === null) skip('no credentials on this channel');
        $t = new FakeTransport();
        $r = adapter_with($key, $t)->sendText(OutboundMessage::text($spec['recipient'], 'x', array()));
        assert_false($r->ok);
        assert_false($r->retryable);
        assert_count(0, $t->requests, 'must not call the API without credentials');
    });

    foreach ($spec['inbound'] as $fixture => $exp) {
        t("$key: normalizeInbound($fixture)", function () use ($key, $fixture, $exp) {
            $a = ChannelRegistry::for($key, new FakeTransport());
            $m = $a->normalizeInbound(fixture($fixture), array('tenant_id' => 42, 'account_id' => 9));
            if ($exp === null) { assert_null($m, 'not an inbound customer message'); return; }
            assert_true($m instanceof InboundMessage, 'expected an InboundMessage');
            assert_same(42, $m->tenant_id);
            assert_same($key, $m->channel);
            assert_same($exp['text'], $m->text, 'text');
            assert_same($exp['id'], $m->external_message_id, 'external_message_id');
            assert_same($exp['subscriber'], $m->subscriber_id, 'subscriber_id');
            assert_same($exp['account'], $m->channel_account_id, 'channel_account_id');
            assert_count($exp['attachments'], $m->attachments, 'attachments');
            if (isset($exp['attachment_type'])) assert_same($exp['attachment_type'], $m->attachments[0]['type']);
            if (isset($exp['name'])) assert_same($exp['name'], $m->sender['name'], 'sender name');
            if (isset($exp['reply_to'])) assert_same($exp['reply_to'], $m->reply_to, 'reply_to');
            assert_true($m->timestamp === null || $m->timestamp > 1700000000, 'timestamp in unix seconds');
            // attachments have the documented shape
            foreach ($m->attachments as $att) {
                assert_same(array('type', 'url', 'id', 'mime', 'caption'), array_keys($att), 'attachment shape');
            }
        });
    }

    t("$key: duplicate delivery keeps the same external id (idempotency key)", function () use ($key, $spec) {
        $a = ChannelRegistry::for($key, new FakeTransport());
        $with_id = null;
        foreach ($spec['inbound'] as $fixture => $exp) {
            if ($exp !== null && $exp['id'] !== null) { $with_id = $fixture; break; }
        }
        if ($with_id === null) skip('channel has no inbound messages');
        $first  = $a->normalizeInbound(fixture($with_id), array('tenant_id' => 1));
        $second = $a->normalizeInbound(fixture($with_id), array('tenant_id' => 1));
        assert_true($first !== null && $second !== null);
        assert_true($first->external_message_id !== null && $first->external_message_id !== '', 'id must be present');
        assert_same($first->external_message_id, $second->external_message_id);
        assert_same($first->toArray(), $second->toArray(), 'parsing is deterministic');
    });

    t("$key: malformed, unknown and foreign payloads -> null, no exception", function () use ($key, $spec) {
        $a = ChannelRegistry::for($key, new FakeTransport());
        foreach (malformed_payloads() as $i => $p) {
            assert_null($a->normalizeInbound($p, array()), "malformed payload #$i");
            assert_same(array(), $a->normalizeInboundBatch($p, array()), "malformed payload #$i (batch)");
        }
        // every other channel's fixtures are foreign to this adapter
        $own = array_keys($spec['inbound']);
        foreach (all_fixture_names() as $f) {
            if (in_array($f, $own, true)) continue;
            $prefix = explode('_', $f)[0];
            $same_family = ($key === 'fb' && $prefix === 'fb') || ($key === 'ig' && $prefix === 'ig')
                || ($key === 'wa_openwa' && $prefix === 'openwa') || ($key === 'wa_cloud' && $f !== 'wa_cloud' && strpos($f, 'wa_cloud') === 0)
                || ($key === 'tg' && $prefix === 'tg') || ($key === 'web' && $prefix === 'web');
            if ($same_family) continue;
            assert_null($a->normalizeInbound(fixture($f), array()), "foreign fixture $f must not parse");
        }
    });
}

// ---- adapter-specific cases the common suite cannot express -------------------

t('fb: batch keeps every message, drops echoes and read receipts', function () {
    $list = ChannelRegistry::for('fb', new FakeTransport())->normalizeInboundBatch(fixture('fb_batch'), array('tenant_id' => 1));
    assert_count(2, $list);
    assert_same('m_batch-1', $list[0]->external_message_id);
    assert_same('m_batch-2', $list[1]->external_message_id);
    assert_same('7101234567890999', $list[1]->subscriber_id);
});

t('fb: a single messaging event is accepted as raw input', function () {
    $ev = fixture('fb_text')['entry'][0]['messaging'][0];
    $m = ChannelRegistry::for('fb', new FakeTransport())->normalizeInbound($ev, array());
    assert_same('m_AbCdEf123-fb-text', $m->external_message_id);
    assert_same(1758542400, $m->timestamp);
});

t('wa_openwa: send image mirrors Openwa_api::send_image()', function () {
    $specs = adapter_specs();
    $t = FakeTransport::replying(201, array('id' => 'img-1'));
    $r = ChannelRegistry::for('wa_openwa', $t)->sendMedia(OutboundMessage::media('01001234567', 'image', 'https://cdn.example.com/menu.jpg', 'المنيو', $specs['wa_openwa']['account']));
    assert_true($r->ok, (string) $r->error);
    assert_request_equals($specs['wa_openwa']['image_request'], $t->only());
    // no caption key when caption is empty (legacy behaviour)
    $t2 = FakeTransport::replying(201, array());
    ChannelRegistry::for('wa_openwa', $t2)->sendMedia(OutboundMessage::media('01001234567', 'image', 'https://cdn.example.com/menu.jpg', '', $specs['wa_openwa']['account']));
    assert_same('{"chatId":"201001234567@c.us","url":"https:\/\/cdn.example.com\/menu.jpg"}', $t2->only()->body);
});

t('wa_openwa: LID and group JIDs keep their suffix (Openwa_api::to_chat_id)', function () {
    $specs = adapter_specs();
    $t = FakeTransport::replying(201, array());
    ChannelRegistry::for('wa_openwa', $t)->sendText(OutboundMessage::text('123456789012345@lid', 'x', $specs['wa_openwa']['account']));
    assert_same('123456789012345@lid', json_decode($t->only()->body, true)['chatId']);
});

t('wa_openwa: inbound LID message keeps the phone author as reply target', function () {
    $m = ChannelRegistry::for('wa_openwa', new FakeTransport())->normalizeInbound(fixture('openwa_image'), array());
    assert_same('201001234567@c.us', $m->metadata['reply_chat_id']);
    assert_true($m->metadata['is_lid']);
    assert_same('monkey-main', $m->metadata['session_id']);
});

t('wa_openwa: group messages are ignored like Openwa_bot does', function () {
    $p = fixture('openwa_text');
    $p['data']['isGroup'] = true;
    assert_null(ChannelRegistry::for('wa_openwa', new FakeTransport())->normalizeInbound($p, array()));
});

t('wa_openwa: 2xx body without an id -> ok, no invented id', function () {
    $specs = adapter_specs();
    $r = ChannelRegistry::for('wa_openwa', FakeTransport::replying(200, 'OK'))->sendText(OutboundMessage::text('201001234567', 'x', $specs['wa_openwa']['account']));
    assert_true($r->ok);
    assert_null($r->external_message_id);
});

t('wa_cloud: status-only callbacks yield an empty batch', function () {
    assert_same(array(), ChannelRegistry::for('wa_cloud', new FakeTransport())->normalizeInboundBatch(fixture('wa_cloud_status'), array()));
});

t('tg: an edit gets its own idempotency key', function () {
    $a = ChannelRegistry::for('tg', new FakeTransport());
    $orig = $a->normalizeInbound(fixture('tg_text'), array());
    $edit = $a->normalizeInbound(fixture('tg_edited'), array());
    assert_true($orig->external_message_id !== $edit->external_message_id);
    assert_true($edit->metadata['edited']);
});

t('tg: photo uses the largest size file_id', function () {
    $m = ChannelRegistry::for('tg', new FakeTransport())->normalizeInbound(fixture('tg_photo'), array());
    assert_same('AgAC-large', $m->attachments[0]['id']);
});

t('web: without an outbox the adapter refuses instead of pretending', function () {
    $r = ChannelRegistry::for('web', new FakeTransport())->sendText(OutboundMessage::text('web_x', 'hi'));
    assert_false($r->ok);
    assert_false($r->retryable);
});

t('web: first message has no session yet -> thread NULL, still parsed', function () {
    $m = ChannelRegistry::for('web', new FakeTransport())->normalizeInbound(array('widget_key' => 'wk_1', 'session_key' => '', 'message' => ' hi '), array());
    assert_same('hi', $m->text);
    assert_null($m->external_thread_id);
    assert_null($m->external_message_id, 'widget sends no id: none is invented');
});

t('tiktok: no capabilities, text and media unsupported', function () {
    $a = ChannelRegistry::for('tiktok', new FakeTransport());
    assert_same(array(), $a->capabilities());
    assert_true($a->sendText(OutboundMessage::text('u', 'x'))->unsupported);
    assert_true($a->sendMedia(OutboundMessage::media('u', 'image', 'https://x/y.jpg'))->unsupported);
});

t('registry: unknown key throws, channel_adapter() returns null', function () {
    require_once APPPATH . 'helpers/channel_adapter_helper.php';
    assert_null(channel_adapter('sms'));
    assert_null(channel_adapter('wa')); // ambiguous legacy key: callers must say wa_openwa or wa_cloud
    assert_true(channel_adapter('tg') instanceof \MonkeyBot\Channels\TelegramAdapter);
    $threw = false;
    try { ChannelRegistry::for('sms'); } catch (\InvalidArgumentException $e) { $threw = true; }
    assert_true($threw);
    assert_same(array('fb', 'ig', 'wa_openwa', 'wa_cloud', 'tg', 'web', 'tiktok'), ChannelRegistry::keys());
});

// ---- send_image: every adapter that declares it (brief §44: never fake support) ----------------

foreach (adapter_specs() as $key => $spec) {
    t("$key: send_image declared <=> an image request is specified and tested", function () use ($key, $spec) {
        $declares = ChannelRegistry::for($key, new FakeTransport())->supports(Capability::SEND_IMAGE);
        assert_same($declares, isset($spec['image_request']), 'capability and contract spec must agree');
    });

    if (!isset($spec['image_request'])) continue;
    $caption = (isset($spec['image_caption']) && $spec['image_caption'] === false) ? '' : 'المنيو';

    t("$key: send image -> exact provider request, message id", function () use ($key, $spec, $caption) {
        $t = FakeTransport::replying($spec['ok_response'][0], $spec['ok_response'][1]);
        $r = ChannelRegistry::for($key, $t)->sendMedia(OutboundMessage::media($spec['recipient'], 'image', 'https://cdn.example.com/menu.jpg', $caption, $spec['account'], 7));
        assert_true($r->ok, 'send failed: ' . $r->error);
        assert_false($r->unsupported);
        assert_request_equals($spec['image_request'], $t->only());
        assert_same($spec['ok_id'], $r->external_message_id);
    });

    t("$key: send image without credentials / url / recipient -> no request, not ok, not retryable", function () use ($key, $spec) {
        foreach (array(
            array($spec['recipient'], 'https://cdn.example.com/menu.jpg', array()),
            array($spec['recipient'], '', $spec['account']),
            array('', 'https://cdn.example.com/menu.jpg', $spec['account']),
        ) as $i => $case) {
            $t = new FakeTransport();
            $r = ChannelRegistry::for($key, $t)->sendMedia(OutboundMessage::media($case[0], 'image', $case[1], '', $case[2]));
            assert_false($r->ok, "case $i");
            assert_false($r->retryable, "case $i");
            assert_false($r->unsupported, "case $i: a bad request is not 'unsupported'");
            assert_count(0, $t->requests, "case $i: no HTTP");
        }
    });

    t("$key: send image channel rejection -> exact legacy error wording", function () use ($key, $spec) {
        list($status, $body, $legacy, $retry) = $spec['channel_error'];
        $r = ChannelRegistry::for($key, FakeTransport::replying($status, $body))
            ->sendMedia(OutboundMessage::media($spec['recipient'], 'image', 'https://cdn.example.com/menu.jpg', '', $spec['account']));
        assert_false($r->ok);
        assert_same($legacy, $r->error);
        assert_same($retry, $r->retryable);
    });

    t("$key: send image transport failure / rate limit -> retryable", function () use ($key, $spec) {
        $r = ChannelRegistry::for($key, FakeTransport::failing())
            ->sendMedia(OutboundMessage::media($spec['recipient'], 'image', 'https://cdn.example.com/menu.jpg', '', $spec['account']));
        assert_false($r->ok);
        assert_true($r->retryable);
        list($status, $body) = $spec['rate_error'];
        $r = ChannelRegistry::for($key, FakeTransport::replying($status, $body))
            ->sendMedia(OutboundMessage::media($spec['recipient'], 'image', 'https://cdn.example.com/menu.jpg', '', $spec['account']));
        assert_false($r->ok);
        assert_true($r->retryable);
    });

    t("$key: video / audio / file stay unsupported (only images were added)", function () use ($key, $spec) {
        $t = new FakeTransport();
        foreach (array('video', 'audio', 'file') as $type) {
            $r = ChannelRegistry::for($key, $t)->sendMedia(OutboundMessage::media($spec['recipient'], $type, 'https://cdn.example.com/x', '', $spec['account']));
            assert_true($r->unsupported, $type);
        }
        assert_count(0, $t->requests);
    });
}

t('fb/ig: an image caption is refused, never silently dropped', function () {
    $specs = adapter_specs();
    foreach (array('fb', 'ig') as $key) {
        $t = new FakeTransport();
        $r = ChannelRegistry::for($key, $t)->sendMedia(OutboundMessage::media($specs[$key]['recipient'], 'image', 'https://cdn.example.com/menu.jpg', 'caption', $specs[$key]['account']));
        assert_false($r->ok);
        assert_count(0, $t->requests);
    }
});

t('wa_cloud / tg: no caption key when the caption is empty', function () {
    $specs = adapter_specs();
    $t = FakeTransport::replying(200, $specs['wa_cloud']['ok_response'][1]);
    ChannelRegistry::for('wa_cloud', $t)->sendMedia(OutboundMessage::media('201001234567', 'image', 'https://cdn.example.com/menu.jpg', '', $specs['wa_cloud']['account']));
    assert_same(array('link' => 'https://cdn.example.com/menu.jpg'), json_decode($t->only()->body, true)['image']);
    $t = FakeTransport::replying(200, $specs['tg']['ok_response'][1]);
    ChannelRegistry::for('tg', $t)->sendMedia(OutboundMessage::media('55501234', 'image', 'https://cdn.example.com/menu.jpg', '', $specs['tg']['account']));
    assert_same('chat_id=55501234&photo=https%3A%2F%2Fcdn.example.com%2Fmenu.jpg', $t->only()->body);
});

t('adapters never throw from send even on garbage responses', function () {
    $specs = adapter_specs();
    foreach (array('fb', 'ig', 'wa_openwa', 'wa_cloud', 'tg') as $key) {
        foreach (array('', 'null', '[]', '"str"', '{"error":"flat string"}', '{"messages":"x"}', '{"ok":true,"result":"x"}') as $body) {
            $r = ChannelRegistry::for($key, FakeTransport::replying(200, $body))->sendText(OutboundMessage::text($specs[$key]['recipient'], 'x', $specs[$key]['account']));
            assert_true($r instanceof SendResult, "$key / $body");
        }
    }
});

t('fb/ig: HUMAN_AGENT tag from the policy reaches the payload; no metadata = legacy RESPONSE body', function () {
    $specs = adapter_specs();
    foreach (array('fb', 'ig') as $key) {
        $t = FakeTransport::replying(200, $specs[$key]['ok_response'][1] ?? '{"message_id":"m1"}');
        ChannelRegistry::for($key, $t)->sendText(OutboundMessage::text($specs[$key]['recipient'], 'x', $specs[$key]['account'])
            ->withMetadata(array('messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT')));
        $b = json_decode($t->only()->body, true);
        assert_same('MESSAGE_TAG', $b['messaging_type'], "$key text type");
        assert_same('HUMAN_AGENT', $b['tag'], "$key text tag");

        $t = FakeTransport::replying(200, $specs[$key]['ok_response'][1] ?? '{"message_id":"m1"}');
        ChannelRegistry::for($key, $t)->sendMedia(OutboundMessage::media($specs[$key]['recipient'], 'image', 'https://cdn.example.com/a.jpg', '', $specs[$key]['account'])
            ->withMetadata(array('messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT')));
        $b = json_decode($t->only()->body, true);
        assert_same('HUMAN_AGENT', $b['tag'], "$key image tag");

        // inside 24h the policy returns RESPONSE / no tag: body must be the exact legacy one
        $t = FakeTransport::replying(200, $specs[$key]['ok_response'][1] ?? '{"message_id":"m1"}');
        ChannelRegistry::for($key, $t)->sendText(OutboundMessage::text($specs[$key]['recipient'], 'hello there', $specs[$key]['account'])
            ->withMetadata(array('messaging_type' => 'RESPONSE', 'tag' => null)));
        assert_request_equals($specs[$key]['request']('hello there'), $t->only());
        // an unknown tag is never forwarded
        $t = FakeTransport::replying(200, $specs[$key]['ok_response'][1] ?? '{"message_id":"m1"}');
        ChannelRegistry::for($key, $t)->sendText(OutboundMessage::text($specs[$key]['recipient'], 'hello there', $specs[$key]['account'])
            ->withMetadata(array('messaging_type' => 'MESSAGE_TAG', 'tag' => 'CONFIRMED_EVENT_UPDATE')));
        assert_request_equals($specs[$key]['request']('hello there'), $t->only());
    }
});
