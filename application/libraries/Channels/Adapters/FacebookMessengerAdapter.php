<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/** Facebook Messenger (webhook object 'page'). See MetaMessagingAdapter. */
final class FacebookMessengerAdapter extends MetaMessagingAdapter
{
    public function key(): string { return 'fb'; }
    protected function webhookObject() { return 'page'; }
}
