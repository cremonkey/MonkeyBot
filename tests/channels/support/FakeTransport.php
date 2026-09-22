<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use MonkeyBot\Channels\HttpRequest;
use MonkeyBot\Channels\HttpResponse;
use MonkeyBot\Channels\HttpTransport;

/** Records every request; answers from a queue, then from $default. */
final class FakeTransport implements HttpTransport
{
    /** @var HttpRequest[] */
    public $requests = array();
    /** @var HttpResponse[] */
    public $queue = array();
    public $default;

    public function __construct(?HttpResponse $default = null)
    {
        $this->default = $default ?: new HttpResponse(200, '{}');
    }

    public static function replying($status, $body)
    {
        return new self(new HttpResponse($status, is_string($body) ? $body : json_encode($body)));
    }

    public static function failing($error = 'Connection timed out after 15001 milliseconds')
    {
        return new self(new HttpResponse(0, null, $error));
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        return $this->queue ? array_shift($this->queue) : $this->default;
    }

    public function only()
    {
        assert_count(1, $this->requests, 'HTTP requests made');
        return $this->requests[0];
    }
}
