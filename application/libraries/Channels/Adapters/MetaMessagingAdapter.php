<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shared Messenger Platform logic for Facebook Messenger and Instagram DMs.
 * Both send through the same Graph endpoint with the page access token, which
 * is exactly what channel_send_fb_ig() always did for both platforms.
 */
abstract class MetaMessagingAdapter extends AbstractChannelAdapter
{
    /** Graph error codes that mean "try again later" (service, rate limits, temporary send failure). */
    const TRANSIENT_CODES = array(1, 2, 4, 17, 32, 341, 613, 1200);

    /** The webhook `object` value this adapter accepts ('page' or 'instagram'). */
    abstract protected function webhookObject();

    public function capabilities(): array
    {
        // send_image: Messenger Platform Send API, image attachment by public URL (the payload
        // Livechat::reply_to_conversation already sends for media, with messaging_type RESPONSE
        // like sendText). Video / audio / file attachments are not implemented here.
        return array(Capability::SEND_TEXT, Capability::SEND_IMAGE, Capability::RECEIVE_WEBHOOK);
    }

    public function sendText(OutboundMessage $message): SendResult
    {
        $token = $message->credential('page_access_token');
        if ($token === '') return $this->invalid('page_access_token missing');
        if ((string) $message->recipient === '' || $message->text === '') return $this->invalid('empty message or recipient');

        // Byte-for-byte the legacy request: the legacy-tier "me/messages" endpoint
        // (same path Home::send_reply uses for all webhook replies), token in the
        // query string, JSON body, 20s timeout, peer verification on.
        $payload = json_encode(self::withSendType(array(
            'recipient'      => array('id' => $message->recipient),
            'messaging_type' => 'RESPONSE',
            'message'        => array('text' => $message->text),
        ), $message));
        $res = $this->http->send(new HttpRequest(
            'POST',
            graph_url_group('send', 'me/messages?access_token=') . urlencode($token),
            array('Content-Type: application/json'),
            $payload,
            20,
            null,
            true,
            null
        ));
        return $this->interpretGraph($res);
    }

    /**
     * Image by public URL: {recipient:{id}, messaging_type:RESPONSE,
     * message:{attachment:{type:image, payload:{url, is_reusable:false}}}}, sent to the same
     * endpoint with the same options as sendText. The Send API has no image caption, so a
     * caption is refused instead of being silently dropped.
     */
    public function sendMedia(OutboundMessage $message): SendResult
    {
        $media = self::arr($message->media);
        if (($media['type'] ?? '') !== 'image') return parent::sendMedia($message);
        $token = $message->credential('page_access_token');
        if ($token === '') return $this->invalid('page_access_token missing');
        $url = (string) ($media['url'] ?? '');
        if ((string) $message->recipient === '' || $url === '') return $this->invalid('empty media url or recipient');
        if ((string) ($media['caption'] ?? '') !== '') return $this->invalid('image captions are not supported on this channel');

        $payload = json_encode(self::withSendType(array(
            'recipient'      => array('id' => $message->recipient),
            'messaging_type' => 'RESPONSE',
            'message'        => array('attachment' => array('type' => 'image', 'payload' => array('url' => $url, 'is_reusable' => false))),
        ), $message));
        $res = $this->http->send(new HttpRequest(
            'POST',
            graph_url_group('send', 'me/messages?access_token=') . urlencode($token),
            array('Content-Type: application/json'),
            $payload,
            20,
            null,
            true,
            null
        ));
        return $this->interpretGraph($res);
    }

    /**
     * Outside the 24h window the policy engine allows a human reply only as
     * messaging_type MESSAGE_TAG + tag HUMAN_AGENT (up to 7 days). The caller passes that
     * decision in the message metadata; without it the payload is exactly the legacy
     * RESPONSE body (key order included), so every existing caller is byte-identical.
     */
    protected static function withSendType(array $payload, OutboundMessage $message)
    {
        $type = (string) ($message->metadata['messaging_type'] ?? '');
        $tag  = (string) ($message->metadata['tag'] ?? '');
        if ($type === 'MESSAGE_TAG' && $tag === 'HUMAN_AGENT') {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = 'HUMAN_AGENT';
        }
        return $payload;
    }

    protected function interpretGraph(HttpResponse $res)
    {
        $body = $res->json();
        if (is_array($body) && !empty($body['error'])) {
            $err  = $body['error'];
            $code = is_array($err) && isset($err['code']) ? $err['code'] : null;
            // exact legacy wording
            $msg  = 'graph: ' . ((is_array($err) && isset($err['message'])) ? $err['message'] : 'unknown')
                  . ' (code ' . ($code !== null ? $code : '?') . ')';
            $retry = self::retryableHttp($res)
                || (is_array($err) && !empty($err['is_transient']))
                || in_array((int) $code, self::TRANSIENT_CODES, true);
            return SendResult::failure($msg, $retry, $res->status, $body);
        }
        if ($res->transportFailed()) {
            return SendResult::failure('graph: transport error: ' . $res->error, true, 0, null);
        }
        if ($res->status < 200 || $res->status >= 300 || !is_array($body)) {
            return SendResult::failure('graph: unexpected response (HTTP ' . $res->status . ')', self::retryableHttp($res), $res->status, $body);
        }
        return SendResult::success(isset($body['message_id']) ? self::str($body['message_id']) : null, $res->status, $body);
    }

    /**
     * Accepts a full webhook body ({object, entry[].messaging[]}) or a single
     * messaging event ({sender, recipient, message|postback}).
     */
    protected function parseInbound(array $raw, array $context)
    {
        $events = array();
        if (isset($raw['sender']) && (isset($raw['message']) || isset($raw['postback']))) {
            $events[] = array($raw, null);
        } else {
            if (!isset($raw['object']) || $raw['object'] !== $this->webhookObject()) return array();
            foreach (self::arr($raw['entry'] ?? null) as $entry) {
                $entry = self::arr($entry);
                foreach (self::arr($entry['messaging'] ?? null) as $ev) {
                    $events[] = array(self::arr($ev), self::str($entry['id'] ?? null));
                }
            }
        }

        $out = array();
        foreach ($events as $pair) {
            list($ev, $entry_id) = $pair;
            $m = $this->parseEvent($ev, $entry_id, $context);
            if ($m !== null) $out[] = $m;
        }
        return $out;
    }

    private function parseEvent(array $ev, $entry_id, array $context)
    {
        $sender_id = self::str(self::arr($ev['sender'] ?? null)['id'] ?? null);
        if ($sender_id === null || $sender_id === '') return null;
        $recipient_id = self::str(self::arr($ev['recipient'] ?? null)['id'] ?? null);
        $ts_ms = isset($ev['timestamp']) && is_numeric($ev['timestamp']) ? (int) $ev['timestamp'] : null;

        $meta = array();
        if ($ts_ms !== null) $meta['timestamp_ms'] = $ts_ms;
        $text = '';
        $attachments = array();
        $reply_to = null;
        $mid = null;

        if (isset($ev['message']) && is_array($ev['message'])) {
            $msg = $ev['message'];
            // page's own outgoing messages (bot replies, Business Suite agents) and deletions are not inbound
            if (!empty($msg['is_echo']) || !empty($msg['is_deleted'])) return null;
            $mid  = self::str($msg['mid'] ?? null);
            $text = (string) (self::str($msg['text'] ?? null) ?? '');
            foreach (self::arr($msg['attachments'] ?? null) as $a) {
                $a = self::arr($a);
                $type = self::str($a['type'] ?? null) ?? 'other';
                $payload = self::arr($a['payload'] ?? null);
                if (!in_array($type, array('image', 'video', 'audio', 'file', 'location'), true)) {
                    $type = isset($payload['sticker_id']) ? 'sticker' : 'other';
                }
                $attachments[] = self::attachment($type, $payload['url'] ?? null,
                    $payload['sticker_id'] ?? ($payload['attachment_id'] ?? null));
            }
            $rt = self::arr($msg['reply_to'] ?? null);
            if (isset($rt['mid'])) $reply_to = self::str($rt['mid']);
            if (isset($rt['story'])) $meta['reply_to_story'] = self::arr($rt['story']);
            if (isset($msg['quick_reply']['payload'])) $meta['quick_reply_payload'] = self::str($msg['quick_reply']['payload']);
            if (isset($msg['referral'])) $meta['referral'] = self::arr($msg['referral']);
        } elseif (isset($ev['postback']) && is_array($ev['postback'])) {
            $pb   = $ev['postback'];
            $mid  = self::str($pb['mid'] ?? null);
            $text = (string) (self::str($pb['title'] ?? null) ?? '');
            $meta['postback_payload'] = self::str($pb['payload'] ?? null);
        } else {
            return null; // read / delivery / reaction / optin etc.
        }

        return new InboundMessage(
            self::tenant($context),
            $this->key(),
            $recipient_id !== null ? $recipient_id : ($entry_id !== null ? $entry_id : self::contextAccount($context)),
            $sender_id,
            $sender_id,
            $mid,
            array('id' => $sender_id, 'name' => null),
            $text,
            $attachments,
            $reply_to,
            $ts_ms !== null ? intdiv($ts_ms, 1000) : null,
            $meta
        );
    }
}
