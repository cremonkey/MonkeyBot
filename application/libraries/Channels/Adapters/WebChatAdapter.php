<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Website chat widget. There is no push API: outbound delivery is a row the
 * widget's poller (Webchat::poll) picks up. The adapter itself holds no DB
 * handle; the caller supplies an "outbox" callable that writes that row
 * (channel_send_web() passes one that calls channel_log_message()).
 * Without an outbox, sendText() fails instead of pretending to deliver.
 */
final class WebChatAdapter extends AbstractChannelAdapter
{
    /** @var callable|null fn(OutboundMessage $m): void */
    private $outbox;

    public function key(): string { return 'web'; }

    public function capabilities(): array
    {
        return array(Capability::SEND_TEXT, Capability::RECEIVE_WEBHOOK);
    }

    /** Copy of this adapter that delivers through $outbox. */
    public function withOutbox(callable $outbox)
    {
        $c = clone $this;
        $c->outbox = $outbox;
        return $c;
    }

    public function sendText(OutboundMessage $message): SendResult
    {
        if ((string) $message->recipient === '' || $message->text === '') return $this->invalid('empty message or recipient');
        if ($this->outbox === null) return $this->invalid('no outbox configured');
        try {
            call_user_func($this->outbox, $message);
        } catch (\Throwable $e) {
            // a failed insert may well succeed on retry (lock wait, lost connection).
            // Error text is the exception message verbatim, as channel_send_text() always returned it.
            return SendResult::failure($e->getMessage(), true);
        }
        return SendResult::success();
    }

    /**
     * Payload: the widget's POST fields as Webchat::send() reads them:
     * {widget_key, session_key, message, page_url, client_message_id?}.
     * session_key is empty on a visitor's first message (the controller then
     * creates one), so thread/subscriber may be NULL. The widget sends no
     * message id today, so external_message_id is NULL unless client_message_id is given.
     * Input caps (2000 chars) and XSS filtering stay in the controller.
     */
    protected function parseInbound(array $raw, array $context)
    {
        $widget = self::str($raw['widget_key'] ?? null);
        if ($widget === null || $widget === '') return array();
        $text = trim((string) (self::str($raw['message'] ?? null) ?? ''));
        if ($text === '') return array();
        $sk = self::str($raw['session_key'] ?? null);
        if ($sk === '') $sk = null;
        $meta = array();
        if (isset($raw['page_url'])) $meta['page_url'] = self::str($raw['page_url']);

        return array(new InboundMessage(
            self::tenant($context),
            $this->key(),
            $widget,
            $sk,
            $sk,
            self::str($raw['client_message_id'] ?? null),
            array('id' => $sk, 'name' => null),
            $text,
            array(),
            null,
            isset($context['received_at']) && is_int($context['received_at']) ? $context['received_at'] : null,
            $meta
        ));
    }
}
