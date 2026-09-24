<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Meta WhatsApp Cloud API. Requests are the ones Whatsapp_api::post() builds:
 * graph.facebook.com/v21.0/{phone_number_id}/messages (hardcoded v21.0, NOT graph_url()),
 * Bearer token, 30s timeout, full TLS verification.
 *
 * Templates exist in Whatsapp_api::send_template() but are not exposed here yet,
 * so 'templates' is not declared. send_image is the Cloud API image message by link
 * ({type:image, image:{link, caption?}}) to the same endpoint with the same options.
 */
final class WhatsAppCloudAdapter extends AbstractChannelAdapter
{
    const GRAPH_BASE = 'https://graph.facebook.com/v21.0/';

    /** Cloud API codes that mean "try again later": throttling, rate limits, service unavailable. */
    const TRANSIENT_CODES = array(1, 2, 4, 80007, 130429, 131000, 131016, 131056);

    public function key(): string { return 'wa_cloud'; }

    public function capabilities(): array
    {
        return array(Capability::SEND_TEXT, Capability::SEND_IMAGE, Capability::MARK_SEEN, Capability::RECEIVE_WEBHOOK);
    }

    /**
     * Cloud API mark-as-read: POST {phone_number_id}/messages
     * {messaging_product:whatsapp, status:read, message_id}. The inbound message
     * id is not a recipient the adapter is handed elsewhere, so it must come
     * from the caller: pass an OutboundMessage with metadata['message_id'] set
     * (a bare recipient string has no id to mark, so the call fails without HTTP).
     */
    public function markSeen(OutboundMessage|string $recipient, array $account = array()): SendResult
    {
        $acct = self::accountOf($recipient, $account);
        $meta = self::metadataOf($recipient);
        $token = isset($acct['access_token']) ? (string) $acct['access_token'] : '';
        $pnid  = isset($acct['phone_number_id']) ? (string) $acct['phone_number_id'] : '';
        $mid   = isset($meta['message_id']) ? (string) $meta['message_id'] : '';
        if ($token === '') return $this->invalid('access_token missing');
        if ($pnid === '') return $this->invalid('phone_number_id missing');
        if ($mid === '') return $this->invalid('message_id missing');

        $res = $this->request($token, $pnid, array(
            'messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $mid,
        ));
        $body = $res->json(); // {"success": true} on the Cloud API mark-as-read endpoint
        if (empty($body['success'])) {
            $err  = isset($body['error']) ? $body['error'] : null;
            $code = is_array($err) && isset($err['code']) ? (int) $err['code'] : null;
            $retry = self::retryableHttp($res) || in_array($code, self::TRANSIENT_CODES, true);
            return SendResult::failure('wa: ' . json_encode($err !== null ? $err : $body), $retry, $res->status, $body);
        }
        return SendResult::success(null, $res->status, $body);
    }

    public function sendText(OutboundMessage $message): SendResult
    {
        $token = $message->credential('access_token');
        $pnid  = $message->credential('phone_number_id');
        if ($token === '') return $this->invalid('access_token missing');
        if ($pnid === '') return $this->invalid('phone_number_id missing');
        if ((string) $message->recipient === '' || $message->text === '') return $this->invalid('empty message or recipient');

        return $this->post($token, $pnid, array(
            'messaging_product' => 'whatsapp', 'to' => $message->recipient, 'type' => 'text',
            'text' => array('preview_url' => true, 'body' => $message->text),
        ));
    }

    public function sendMedia(OutboundMessage $message): SendResult
    {
        $media = self::arr($message->media);
        if (($media['type'] ?? '') !== 'image') return parent::sendMedia($message);
        $token = $message->credential('access_token');
        $pnid  = $message->credential('phone_number_id');
        if ($token === '') return $this->invalid('access_token missing');
        if ($pnid === '') return $this->invalid('phone_number_id missing');
        $url = (string) ($media['url'] ?? '');
        if ((string) $message->recipient === '' || $url === '') return $this->invalid('empty media url or recipient');
        $image = array('link' => $url);
        $caption = (string) ($media['caption'] ?? '');
        if ($caption !== '') $image['caption'] = $caption;
        return $this->post($token, $pnid, array(
            'messaging_product' => 'whatsapp', 'to' => $message->recipient, 'type' => 'image', 'image' => $image,
        ));
    }

    private function request($token, $pnid, array $payload)
    {
        return $this->http->send(new HttpRequest(
            'POST',
            self::GRAPH_BASE . $pnid . '/messages',
            array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
            json_encode($payload),
            30,
            null,
            true,
            2
        ));
    }

    private function post($token, $pnid, array $payload)
    {
        $res = $this->request($token, $pnid, $payload);
        $body = $res->json(); // what Whatsapp_api::post() returned
        if (empty($body['messages'])) {
            $err  = isset($body['error']) ? $body['error'] : null;
            $code = is_array($err) && isset($err['code']) ? (int) $err['code'] : null;
            $retry = self::retryableHttp($res) || in_array($code, self::TRANSIENT_CODES, true);
            // exact legacy wording
            return SendResult::failure('wa: ' . json_encode($err !== null ? $err : $body), $retry, $res->status, $body);
        }
        $first = self::arr(self::arr($body['messages'])[0] ?? null);
        return SendResult::success(self::str($first['id'] ?? null), $res->status, $body);
    }

    /**
     * Payload: {object:'whatsapp_business_account', entry[].changes[].value:{metadata:{phone_number_id},
     * contacts[{wa_id, profile:{name}}], messages[{from, id, timestamp, type, text|image|audio|..., context?}]}}.
     * Status callbacks (value.statuses) carry no messages and yield nothing.
     */
    protected function parseInbound(array $raw, array $context)
    {
        if (isset($raw['object']) && $raw['object'] !== 'whatsapp_business_account') return array();
        $out = array();
        foreach (self::arr($raw['entry'] ?? null) as $entry) {
            foreach (self::arr(self::arr($entry)['changes'] ?? null) as $change) {
                $value = self::arr(self::arr($change)['value'] ?? null);
                $pnid  = self::str(self::arr($value['metadata'] ?? null)['phone_number_id'] ?? null);
                $names = array();
                foreach (self::arr($value['contacts'] ?? null) as $c) {
                    $c = self::arr($c);
                    $wa = self::str($c['wa_id'] ?? null);
                    $nm = self::str(self::arr($c['profile'] ?? null)['name'] ?? null);
                    if ($wa !== null && $nm !== null) $names[$wa] = $nm;
                }
                foreach (self::arr($value['messages'] ?? null) as $m) {
                    $msg = $this->parseMessage(self::arr($m), $pnid, $names, $context);
                    if ($msg !== null) $out[] = $msg;
                }
            }
        }
        return $out;
    }

    private function parseMessage(array $m, $pnid, array $names, array $context)
    {
        $from = self::str($m['from'] ?? null);
        if ($from === null || $from === '') return null; // same skip as Whatsapp_bot::webhook()
        $type = (string) (self::str($m['type'] ?? null) ?? '');
        $text = (string) (self::str(self::arr($m['text'] ?? null)['body'] ?? null) ?? '');

        $attachments = array();
        $kinds = array('image' => 'image', 'video' => 'video', 'audio' => 'audio', 'voice' => 'audio',
                       'document' => 'file', 'sticker' => 'sticker');
        if (isset($kinds[$type])) {
            $media = self::arr($m[$type] ?? null);
            $caption = self::str($media['caption'] ?? null);
            $attachments[] = self::attachment($kinds[$type], null, $media['id'] ?? null, $media['mime_type'] ?? null, $caption);
            if ($text === '' && $caption !== null) $text = $caption;
        } elseif ($type === 'location') {
            $loc = self::arr($m['location'] ?? null);
            $attachments[] = self::attachment('location', null, null, null, self::str($loc['name'] ?? null));
        } elseif ($type === 'button') {
            $text = (string) (self::str(self::arr($m['button'] ?? null)['text'] ?? null) ?? '');
        } elseif ($type === 'interactive') {
            $i = self::arr($m['interactive'] ?? null);
            $reply = self::arr($i['button_reply'] ?? ($i['list_reply'] ?? null));
            $text = (string) (self::str($reply['title'] ?? null) ?? '');
        }

        $account = $pnid !== null ? $pnid : self::contextAccount($context);
        $meta = array('message_type' => $type);
        if ($pnid !== null) $meta['phone_number_id'] = $pnid;

        return new InboundMessage(
            self::tenant($context),
            $this->key(),
            $account,
            $from,
            $from,
            self::str($m['id'] ?? null),
            array('id' => $from, 'name' => isset($names[$from]) ? $names[$from] : null),
            $text,
            $attachments,
            self::str(self::arr($m['context'] ?? null)['id'] ?? null),
            isset($m['timestamp']) && is_numeric($m['timestamp']) ? (int) $m['timestamp'] : null,
            $meta
        );
    }
}
