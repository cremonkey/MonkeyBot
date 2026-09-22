<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Key -> adapter lookup. Every adapter built here shares one HttpTransport:
 * CurlTransport in production, a fake in tests (ChannelRegistry::useTransport()).
 */
final class ChannelRegistry
{
    const ADAPTERS = array(
        'fb'        => FacebookMessengerAdapter::class,
        'ig'        => InstagramAdapter::class,
        'wa_openwa' => OpenWaAdapter::class,
        'wa_cloud'  => WhatsAppCloudAdapter::class,
        'tg'        => TelegramAdapter::class,
        'web'       => WebChatAdapter::class,
        'tiktok'    => TikTokAdapter::class,
    );

    /** @var HttpTransport|null */
    private static $transport = null;

    /** @return string[] */
    public static function keys()
    {
        return array_keys(self::ADAPTERS);
    }

    public static function has($key)
    {
        return is_string($key) && isset(self::ADAPTERS[$key]);
    }

    /**
     * @throws \InvalidArgumentException for an unknown key
     */
    public static function for($key, ?HttpTransport $transport = null): ChannelAdapterInterface
    {
        if (!self::has($key)) throw new \InvalidArgumentException('unknown channel adapter: ' . (is_scalar($key) ? $key : gettype($key)));
        $class = self::ADAPTERS[$key];
        return new $class($transport ?: self::transport());
    }

    /** Swap the shared transport (tests). Pass NULL to go back to curl. */
    public static function useTransport(?HttpTransport $transport)
    {
        self::$transport = $transport;
    }

    private static function transport()
    {
        if (self::$transport === null) self::$transport = new CurlTransport();
        return self::$transport;
    }
}
