<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * WhatsApp through the self-hosted OpenWA-compatible gateway
 * (Evolution + webhook-router "openwaCompat" transform).
 *
 * Requests are the ones Openwa_api::request() builds: {base_url}/api/sessions/{id}/messages/send-*,
 * X-API-Key header, 15s timeout / 5s connect timeout, full TLS verification.
 * Chat-id normalisation is delegated to Openwa_api::to_chat_id() so there is one copy of it.
 */
final class OpenWaAdapter extends AbstractChannelAdapter
{
    /** @var \Openwa_api */
    private $ids;

    public function __construct(?HttpTransport $http = null)
    {
        parent::__construct($http);
        $this->ids = new \Openwa_api();
    }

    public function key(): string { return 'wa_openwa'; }

    public function capabilities(): array
    {
        // send_image mirrors Openwa_api::send_image(), which Messenger_bot uses in production.
        return array(Capability::SEND_TEXT, Capability::SEND_IMAGE, Capability::RECEIVE_WEBHOOK);
    }

    public function sendText(OutboundMessage $message): SendResult
    {
        if (($bad = $this->checkAccount($message)) !== null) return $bad;
        if ((string) $message->recipient === '' || $message->text === '') return $this->invalid('empty message or recipient');
        return $this->post($message, '/messages/send-text', array(
            'chatId' => $this->ids->to_chat_id($message->recipient),
            'text'   => $message->text,
        ));
    }

    public function sendMedia(OutboundMessage $message): SendResult
    {
        $media = self::arr($message->media);
        if (($media['type'] ?? '') !== 'image') return parent::sendMedia($message);
        if (($bad = $this->checkAccount($message)) !== null) return $bad;
        $url = (string) ($media['url'] ?? '');
        if ((string) $message->recipient === '' || $url === '') return $this->invalid('empty media url or recipient');
        $payload = array('chatId' => $this->ids->to_chat_id($message->recipient), 'url' => $url);
        $caption = (string) ($media['caption'] ?? '');
        if ($caption !== '') $payload['caption'] = $caption;
        return $this->post($message, '/messages/send-image', $payload);
    }

    private function checkAccount(OutboundMessage $m)
    {
        foreach (array('base_url', 'api_key', 'session_id') as $k) {
            if ($m->credential($k) === '') return $this->invalid($k . ' missing');
        }
        return null;
    }

    private function post(OutboundMessage $m, $suffix, array $payload)
    {
        $url = rtrim($m->credential('base_url'), '/') . '/api/sessions/' . rawurlencode($m->credential('session_id')) . $suffix;
        $res = $this->http->send(new HttpRequest(
            'POST',
            $url,
            array('Content-Type: application/json', 'Accept: application/json', 'X-API-Key: ' . $m->credential('api_key')),
            json_encode($payload),
            15,
            5,
            true,
            2
        ));

        // Rebuild exactly what Openwa_api::request() returns, so the error string is unchanged.
        if ($res->transportFailed()) {
            $legacy = array('_http' => 0, '_error' => $res->error !== '' ? $res->error : 'curl failed');
        } else {
            $legacy = json_decode($res->body, true);
            if (!is_array($legacy)) $legacy = array('raw' => $res->body);
            $legacy['_http'] = $res->status;
        }

        $http = (int) $legacy['_http'];
        if ($http < 200 || $http >= 300) {
            return SendResult::failure(
                'openwa: ' . json_encode(isset($legacy['error']) ? $legacy['error'] : $legacy),
                self::retryableHttp($res),
                $res->status,
                $legacy
            );
        }
        return SendResult::success(self::messageId($legacy), $res->status, $legacy);
    }

    /** The gateway's id for the sent message, when its response carries one. */
    private static function messageId(array $r)
    {
        foreach (array($r['id'] ?? null, $r['messageId'] ?? null,
                       self::arr($r['key'] ?? null)['id'] ?? null,
                       self::arr($r['data'] ?? null)['id'] ?? null) as $c) {
            $s = self::str($c);
            if ($s !== null && $s !== '') return $s;
        }
        return null;
    }

    /**
     * Payload: {event:'message.received', sessionId, timestamp, data:{id, from, chatId, fromMe,
     * isGroup, author?, body, type, timestamp, contact:{number?, pushName?, name?}}}.
     * Filters match Openwa_bot::webhook(): other events, fromMe and group messages are not inbound.
     */
    protected function parseInbound(array $raw, array $context)
    {
        $event = isset($raw['event']) ? $raw['event'] : '';
        if ($event !== '' && $event !== 'message.received') return array();
        $data = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
        if (!empty($data['fromMe']) || !empty($data['isGroup'])) return array();

        $from = self::str($data['from'] ?? ($data['chatId'] ?? null));
        if ($from === null || $from === '') return array();

        $contact = self::arr($data['contact'] ?? null);
        // same reply-target choice as Openwa_bot::webhook()
        $reply_chat_id = $from;
        if (!empty($contact['number'])) {
            $reply_chat_id = (string) $contact['number'];
        } elseif (!empty($data['author']) && stripos((string) $data['author'], '@lid') === false) {
            $reply_chat_id = (string) $data['author'];
        }
        $name = null;
        if (!empty($contact['pushName'])) $name = (string) $contact['pushName'];
        elseif (!empty($contact['name'])) $name = (string) $contact['name'];

        $subscribe_id = $this->ids->to_subscribe_id($from);
        $text = (string) (self::str($data['body'] ?? null) ?? '');
        $type = (string) (self::str($data['type'] ?? null) ?? '');

        $attachments = array();
        $kind = self::mediaKind($type);
        if ($kind === null && !empty($data['hasMedia'])) $kind = 'file';
        if ($kind !== null) {
            $media = self::arr($data['media'] ?? null);
            // the gateway does not forward media URLs today, so url stays NULL (fetch by id later)
            $attachments[] = self::attachment($kind, $media['url'] ?? null, $data['id'] ?? null,
                $media['mimetype'] ?? null, $kind !== 'sticker' ? $text : null);
        }

        $ts = $data['timestamp'] ?? ($raw['timestamp'] ?? null);
        $session = self::str($raw['sessionId'] ?? null);
        $account = self::contextAccount($context);

        $quoted = self::str($data['quotedMsgId'] ?? (self::arr($data['quotedMsg'] ?? null)['id'] ?? null));

        return array(new InboundMessage(
            self::tenant($context),
            $this->key(),
            $account !== null ? $account : $session,
            $from,
            $subscribe_id,
            self::str($data['id'] ?? null),
            array('id' => $subscribe_id, 'name' => $name),
            $text,
            $attachments,
            $quoted,
            is_numeric($ts) ? (int) $ts : null,
            array('session_id' => $session, 'reply_chat_id' => $reply_chat_id, 'message_type' => $type,
                  'is_lid' => $this->ids->is_lid($from))
        ));
    }

    /** Baileys messageType (gateway) or classic OpenWA type -> attachment kind; NULL for text. */
    private static function mediaKind($type)
    {
        $map = array(
            'imageMessage' => 'image', 'image' => 'image',
            'videoMessage' => 'video', 'video' => 'video',
            'audioMessage' => 'audio', 'audio' => 'audio', 'ptt' => 'audio', 'voice' => 'audio',
            'documentMessage' => 'file', 'documentWithCaptionMessage' => 'file', 'document' => 'file',
            'stickerMessage' => 'sticker', 'sticker' => 'sticker',
            'locationMessage' => 'location', 'location' => 'location',
        );
        return isset($map[$type]) ? $map[$type] : null;
    }
}
