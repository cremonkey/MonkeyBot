<?php
namespace MonkeyBot\CrmConnectors;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Key -> connector lookup, same shape as Channels\ChannelRegistry.
 */
final class ConnectorRegistry
{
    const CONNECTORS = array(
        '8xcrm'  => ExternalCrmConnector::class,
        'gsheet' => SheetCrmConnector::class,
    );

    /** @return string[] */
    public static function keys()
    {
        return array_keys(self::CONNECTORS);
    }

    public static function has($key)
    {
        return is_string($key) && isset(self::CONNECTORS[$key]);
    }

    /** @throws \InvalidArgumentException for an unknown key */
    public static function for($key): CrmConnectorInterface
    {
        if (!self::has($key)) throw new \InvalidArgumentException('unknown crm connector: ' . (is_scalar($key) ? $key : gettype($key)));
        $class = self::CONNECTORS[$key];
        return new $class();
    }

    /** @return CrmConnectorInterface[] key => instance, in CONNECTORS order */
    public static function all()
    {
        $out = array();
        foreach (self::CONNECTORS as $key => $class) $out[$key] = new $class();
        return $out;
    }
}
