<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Loads the channel adapter layer (CI3 has no autoloader for namespaced
 * libraries). Safe to include more than once. Inside CodeIgniter prefer
 * $this->load->helper('channel_adapter') and channel_adapter($key).
 *
 * The Meta adapters call graph_url_group() (fb_graph_helper, autoloaded in
 * CI); outside CI the caller must provide it.
 */
$__mb_channels = __DIR__;

// Openwa_api is reused for chat-id normalisation. CI's loaders skip the include
// when the class already exists, so loading it here first is harmless.
if (!class_exists('Openwa_api', false)) require_once $__mb_channels . '/../Openwa_api.php';

foreach (array(
    'Capability.php',
    'InboundMessage.php',
    'OutboundMessage.php',
    'SendResult.php',
    'Http.php',
    'ChannelAdapterInterface.php',
    'AbstractChannelAdapter.php',
    'Adapters/MetaMessagingAdapter.php',
    'Adapters/FacebookMessengerAdapter.php',
    'Adapters/InstagramAdapter.php',
    'Adapters/OpenWaAdapter.php',
    'Adapters/WhatsAppCloudAdapter.php',
    'Adapters/TelegramAdapter.php',
    'Adapters/WebChatAdapter.php',
    'Adapters/TikTokAdapter.php',
    'ChannelRegistry.php',
) as $__mb_file) {
    require_once $__mb_channels . '/' . $__mb_file;
}
unset($__mb_channels, $__mb_file);
