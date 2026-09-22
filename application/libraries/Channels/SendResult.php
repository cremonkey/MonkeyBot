<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Outcome of an outbound send.
 *
 * - ok                  true only when the channel accepted the message.
 * - external_message_id the channel's id for the sent message, when it returns one.
 * - error               human-readable reason. For fb/ig/wa/tg the strings are the
 *                       exact ones channel_send_text() has always returned
 *                       ('graph: ...', 'openwa: ...', 'wa: ...', 'tg: ...').
 * - retryable           true when the same request may succeed later (transport
 *                       failure, timeout, HTTP 429/5xx, channel rate-limit codes).
 * - unsupported         true when the adapter does not implement the capability;
 *                       nothing was sent. Always ok=false, retryable=false.
 * - http_status         0 when no HTTP response was received (or no HTTP involved).
 * - response            decoded response body (null when not JSON / no HTTP).
 */
final class SendResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $external_message_id = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
        public readonly bool $unsupported = false,
        public readonly int $http_status = 0,
        public readonly mixed $response = null
    ) {
    }

    public static function success($external_message_id = null, $http_status = 0, $response = null)
    {
        $id = ($external_message_id === null || $external_message_id === '') ? null : (string) $external_message_id;
        return new self(true, $id, null, false, false, (int) $http_status, $response);
    }

    public static function failure($error, $retryable = false, $http_status = 0, $response = null)
    {
        return new self(false, null, (string) $error, (bool) $retryable, false, (int) $http_status, $response);
    }

    public static function unsupported($channel, $capability)
    {
        return new self(false, null, 'unsupported: ' . $capability . ' is not supported on ' . $channel, false, true);
    }
}
