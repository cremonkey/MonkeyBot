<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Normalized outbound message.
 *
 * $account holds the already-resolved, already-DECRYPTED credentials the adapter
 * needs (resolution and secret_decrypt() stay with the caller, so adapters never
 * touch the DB). Keys per adapter:
 *   fb / ig    : page_access_token
 *   wa_openwa  : base_url, api_key, session_id
 *   wa_cloud   : access_token, phone_number_id
 *   tg         : bot_token
 *   web        : (none; delivery goes through the adapter's outbox callable)
 * Never log $account.
 *
 * $recipient is passed through untouched (legacy callers hand in strings from
 * the DB; the JSON payload keeps whatever type it was given). It is untyped on
 * purpose: a NULL from the DB must not become a TypeError; adapters treat it as
 * an empty recipient.
 *
 * $media = array('type' => 'image'|'video'|'audio'|'file', 'url' => string, 'caption' => string)
 */
final class OutboundMessage
{
    public function __construct(
        public readonly mixed $recipient,
        public readonly string $text = '',
        public readonly array $account = array(),
        public readonly ?int $tenant_id = null,
        public readonly ?array $media = null,
        public readonly array $metadata = array()
    ) {
    }

    public static function text($recipient, $text, array $account = array(), $tenant_id = null)
    {
        return new self($recipient, (string) $text, $account, $tenant_id === null ? null : (int) $tenant_id);
    }

    public static function media($recipient, $type, $url, $caption = '', array $account = array(), $tenant_id = null)
    {
        return new self($recipient, (string) $caption, $account, $tenant_id === null ? null : (int) $tenant_id,
            array('type' => (string) $type, 'url' => (string) $url, 'caption' => (string) $caption));
    }

    /** A copy carrying extra send metadata (e.g. the policy's messaging_type / tag). */
    public function withMetadata(array $extra)
    {
        return new self($this->recipient, $this->text, $this->account, $this->tenant_id, $this->media, array_merge($this->metadata, $extra));
    }

    /** Credential lookup; '' when absent. */
    public function credential($key)
    {
        return isset($this->account[$key]) ? (string) $this->account[$key] : '';
    }
}
