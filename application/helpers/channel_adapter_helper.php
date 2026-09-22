<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Channels/bootstrap.php';

if (!function_exists('channel_adapter'))
{
    /**
     * Adapter for a channel key (fb, ig, wa_openwa, wa_cloud, tg, web, tiktok),
     * or NULL for an unknown key. See docs/channels/adapters.md.
     *
     * @param string $key
     * @return \MonkeyBot\Channels\ChannelAdapterInterface|null
     */
    function channel_adapter($key)
    {
        if (!\MonkeyBot\Channels\ChannelRegistry::has($key)) return null;
        return \MonkeyBot\Channels\ChannelRegistry::for($key);
    }
}
