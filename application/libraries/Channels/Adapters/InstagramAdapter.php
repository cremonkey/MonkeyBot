<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/** Instagram DMs (webhook object 'instagram'); sends through the linked page's token. See MetaMessagingAdapter. */
final class InstagramAdapter extends MetaMessagingAdapter
{
    public function key(): string { return 'ig'; }
    protected function webhookObject() { return 'instagram'; }
}
