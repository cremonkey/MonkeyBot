<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Telegram Bot API. Requests are the ones Telegram_api::call() builds:
 * POST api.telegram.org/bot{token}/{method}, form-encoded body (http_build_query,
 * no explicit Content-Type header), 30s timeout, full TLS verification.
 * send_image is sendPhoto with the photo given as a public URL (Bot API: "pass an HTTP URL
 * as a String for Telegram to get a photo from the Internet"), same options as sendMessage.
 */
final class TelegramAdapter extends AbstractChannelAdapter
{
    public function key(): string { return 'tg'; }

    public function capabilities(): array
    {
        return array(Capability::SEND_TEXT, Capability::SEND_IMAGE, Capability::RECEIVE_WEBHOOK);
    }

    public function sendText(OutboundMessage $message): SendResult
    {
        $token = $message->credential('bot_token');
        if ($token === '') return $this->invalid('bot_token missing');
        if ((string) $message->recipient === '' || $message->text === '') return $this->invalid('empty message or recipient');

        return $this->call($token, 'sendMessage', array('chat_id' => $message->recipient, 'text' => $message->text));
    }

    public function sendMedia(OutboundMessage $message): SendResult
    {
        $media = self::arr($message->media);
        if (($media['type'] ?? '') !== 'image') return parent::sendMedia($message);
        $token = $message->credential('bot_token');
        if ($token === '') return $this->invalid('bot_token missing');
        $url = (string) ($media['url'] ?? '');
        if ((string) $message->recipient === '' || $url === '') return $this->invalid('empty media url or recipient');
        $params = array('chat_id' => $message->recipient, 'photo' => $url);
        $caption = (string) ($media['caption'] ?? '');
        if ($caption !== '') $params['caption'] = $caption;
        return $this->call($token, 'sendPhoto', $params);
    }

    private function call($token, $method, array $params)
    {
        $res = $this->http->send(new HttpRequest(
            'POST',
            'https://api.telegram.org/bot' . $token . '/' . $method,
            null,
            http_build_query($params),
            30,
            null,
            true,
            2
        ));

        $body = $res->json(); // what Telegram_api::call() returned
        if (empty($body['ok'])) {
            $code = is_array($body) && isset($body['error_code']) ? (int) $body['error_code'] : 0;
            $retry = self::retryableHttp($res) || $code === 429 || $code >= 500;
            // exact legacy wording
            return SendResult::failure('tg: ' . json_encode($body), $retry, $res->status, $body);
        }
        $result = self::arr($body['result'] ?? null);
        return SendResult::success(self::str($result['message_id'] ?? null), $res->status, $body);
    }

    /**
     * Payload: a Telegram Update. Like Telegram_bot::webhook(), only `message` and
     * `edited_message` with a chat id are read (channel posts, callbacks etc. are not).
     * Telegram message ids are unique per chat only, so the external id is
     * "{chat_id}:{message_id}" (edits append ":e{edit_date}" so an edit is not
     * mistaken for a duplicate of the original).
     */
    protected function parseInbound(array $raw, array $context)
    {
        $edited = !isset($raw['message']) && isset($raw['edited_message']);
        $msg = self::arr($edited ? $raw['edited_message'] : ($raw['message'] ?? null));
        $chat_id = self::str(self::arr($msg['chat'] ?? null)['id'] ?? null);
        if ($chat_id === null || $chat_id === '') return array();

        $from = self::arr($msg['from'] ?? null);
        $name = trim((string) (self::str($from['first_name'] ?? null) ?? '') . ' ' . (string) (self::str($from['last_name'] ?? null) ?? ''));
        $text = self::str($msg['text'] ?? null);
        $caption = self::str($msg['caption'] ?? null);

        $attachments = array();
        if (!empty($msg['photo']) && is_array($msg['photo'])) {
            $sizes = $msg['photo'];
            $largest = self::arr(end($sizes));   // Telegram lists sizes smallest -> largest
            $attachments[] = self::attachment('image', null, $largest['file_id'] ?? null, 'image/jpeg', $caption);
        }
        $kinds = array('video' => 'video', 'audio' => 'audio', 'voice' => 'audio', 'document' => 'file', 'sticker' => 'sticker');
        foreach ($kinds as $field => $kind) {
            if (!empty($msg[$field]) && is_array($msg[$field])) {
                $f = $msg[$field];
                $attachments[] = self::attachment($kind, null, $f['file_id'] ?? null, $f['mime_type'] ?? null, $caption);
            }
        }
        if (!empty($msg['location']) && is_array($msg['location'])) $attachments[] = self::attachment('location');

        $message_id = self::str($msg['message_id'] ?? null);
        $ext = $message_id !== null ? $chat_id . ':' . $message_id : null;
        if ($ext !== null && $edited) $ext .= ':e' . (string) (self::str($msg['edit_date'] ?? null) ?? '');
        $reply = self::str(self::arr($msg['reply_to_message'] ?? null)['message_id'] ?? null);

        $meta = array('update_id' => self::str($raw['update_id'] ?? null), 'chat_type' => self::str(self::arr($msg['chat'])['type'] ?? null));
        if ($edited) $meta['edited'] = true;
        if (isset($from['username'])) $meta['username'] = self::str($from['username']);

        return array(new InboundMessage(
            self::tenant($context),
            $this->key(),
            self::contextAccount($context),
            $chat_id,
            $chat_id,
            $ext,
            array('id' => self::str($from['id'] ?? null) ?? $chat_id, 'name' => $name !== '' ? $name : null),
            $text !== null ? $text : ($caption !== null ? $caption : ''),
            $attachments,
            $reply !== null ? $chat_id . ':' . $reply : null,
            isset($msg['date']) && is_numeric($msg['date']) ? (int) $msg['date'] : null,
            $meta
        ));
    }
}
