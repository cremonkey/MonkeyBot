<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TikTok. There is no public outbound DM API (partner-only), and the existing
 * TikTok integration is comment polling by cron, which is out of scope for this
 * adapter. So: no capabilities, every send is "unsupported", nothing parses.
 * channel_send_text() keeps answering 'unsupported channel: tiktok' as before.
 */
final class TikTokAdapter extends AbstractChannelAdapter
{
    public function key(): string { return 'tiktok'; }

    public function capabilities(): array
    {
        return array();
    }
}
