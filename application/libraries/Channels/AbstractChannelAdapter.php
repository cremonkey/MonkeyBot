<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

abstract class AbstractChannelAdapter implements ChannelAdapterInterface
{
    /** @var HttpTransport */
    protected $http;

    public function __construct(?HttpTransport $http = null)
    {
        $this->http = $http ?: new CurlTransport();
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function sendText(OutboundMessage $message): SendResult
    {
        return SendResult::unsupported($this->key(), Capability::SEND_TEXT);
    }

    public function sendMedia(OutboundMessage $message): SendResult
    {
        $type = isset($message->media['type']) ? $message->media['type'] : '';
        return SendResult::unsupported($this->key(), $type === 'image' ? Capability::SEND_IMAGE : Capability::SEND_MEDIA);
    }

    public function normalizeInbound(array $raw, array $context = array()): ?InboundMessage
    {
        $all = $this->normalizeInboundBatch($raw, $context);
        return empty($all) ? null : $all[0];
    }

    public function normalizeInboundBatch(array $raw, array $context = array()): array
    {
        try {
            $out = array();
            foreach ($this->parseInbound($raw, $context) as $m) {
                if ($m instanceof InboundMessage && $m->hasContent()) $out[] = $m;
            }
            return $out;
        } catch (\Throwable $e) {
            // Parsing must never take a webhook down; a payload we cannot read is "no message".
            return array();
        }
    }

    /** @return InboundMessage[] */
    protected function parseInbound(array $raw, array $context)
    {
        return array();
    }

    // ---- helpers shared by the adapters ----

    /** $v if it is an array, else array() — payload fields are never trusted to have the right type. */
    protected static function arr($v)
    {
        return is_array($v) ? $v : array();
    }

    /** Scalar -> string, anything else -> NULL. */
    protected static function str($v)
    {
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string) $v;
        return null;
    }

    protected static function tenant(array $context)
    {
        return isset($context['tenant_id']) && is_numeric($context['tenant_id']) ? (int) $context['tenant_id'] : null;
    }

    protected static function contextAccount(array $context)
    {
        return isset($context['account_id']) ? self::str($context['account_id']) : null;
    }

    protected static function attachment($type, $url = null, $id = null, $mime = null, $caption = null)
    {
        return array(
            'type' => (string) $type,
            'url' => self::str($url),
            'id' => self::str($id),
            'mime' => self::str($mime),
            'caption' => ($caption === null || $caption === '') ? null : self::str($caption),
        );
    }

    /** Failure for a missing credential or recipient: nothing was sent, retrying will not help. */
    protected function invalid($why)
    {
        return SendResult::failure($this->key() . ': ' . $why, false);
    }

    /** Retry policy shared by all HTTP adapters. */
    protected static function retryableHttp(HttpResponse $res)
    {
        return $res->transportFailed() || $res->status === 0 || $res->status === 429 || $res->status >= 500;
    }
}
