<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Normalized inbound message (brief §11). Produced by
 * ChannelAdapterInterface::normalizeInbound(); carries no behaviour.
 *
 * - tenant_id            owning Monkey Bot user_id, taken from the caller's context
 *                        (a payload never proves which tenant it belongs to).
 * - channel              adapter key: fb|ig|wa_openwa|wa_cloud|tg|web|tiktok.
 * - channel_account_id   the receiving account on that channel: FB page id / IG
 *                        account id (recipient.id), WA Cloud phone_number_id,
 *                        OpenWA/Telegram account row id, web widget_key.
 * - external_thread_id   the conversation on the channel: PSID/IGSID, WA id, chat id,
 *                        web session_key. Today this equals subscriber_id everywhere.
 * - subscriber_id        the customer key the legacy tables use (subscribe_id).
 * - external_message_id  the channel's own message id: the idempotency key. NULL when
 *                        the channel does not provide one (web widget without a
 *                        client_message_id).
 * - sender               array('id' => ..., 'name' => ...|null)
 * - attachments          list of array('type' => image|video|audio|file|sticker|location|other,
 *                        'url' => ?string, 'id' => ?string, 'mime' => ?string, 'caption' => ?string)
 * - reply_to             external id of the quoted message, or NULL
 * - timestamp            unix seconds (int), or NULL when the payload has none
 * - metadata             channel-specific extras (never secrets)
 */
final class InboundMessage
{
    public function __construct(
        public readonly ?int $tenant_id,
        public readonly string $channel,
        public readonly ?string $channel_account_id,
        public readonly ?string $external_thread_id,
        public readonly ?string $subscriber_id,
        public readonly ?string $external_message_id,
        public readonly array $sender,
        public readonly string $text,
        public readonly array $attachments = array(),
        public readonly ?string $reply_to = null,
        public readonly ?int $timestamp = null,
        public readonly array $metadata = array()
    ) {
    }

    public function hasContent()
    {
        return $this->text !== '' || !empty($this->attachments);
    }

    public function toArray()
    {
        return get_object_vars($this);
    }
}
