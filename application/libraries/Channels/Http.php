<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * HTTP seam for adapters. Adapters build an HttpRequest and hand it to an
 * HttpTransport; production uses CurlTransport, tests inject a fake.
 *
 * Nullable fields mean "do not set this curl option" so CurlTransport can
 * reproduce each legacy call's option set exactly (the legacy clients differ:
 * e.g. the Graph send never set CURLOPT_SSL_VERIFYHOST or CURLOPT_CONNECTTIMEOUT,
 * Telegram never set CURLOPT_HTTPHEADER).
 */
final class HttpRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ?array $headers = null,          // list of "Name: value" lines
        public readonly ?string $body = null,
        public readonly ?int $timeout = null,            // CURLOPT_TIMEOUT
        public readonly ?int $connect_timeout = null,    // CURLOPT_CONNECTTIMEOUT
        public readonly bool $verify_peer = true,        // CURLOPT_SSL_VERIFYPEER
        public readonly ?int $verify_host = null         // CURLOPT_SSL_VERIFYHOST
    ) {
    }
}

final class HttpResponse
{
    /**
     * @param int         $status HTTP status; 0 when no response was received
     * @param string|null $body   raw body; NULL when the transport failed (curl_exec() === false)
     * @param string      $error  transport error text ('' on success)
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $body,
        public readonly string $error = ''
    ) {
    }

    public function transportFailed()
    {
        return $this->body === null;
    }

    /** json_decode($body, true), mirroring the legacy clients (NULL on failure). */
    public function json()
    {
        return json_decode((string) $this->body, true);
    }
}

interface HttpTransport
{
    /** Must never throw: transport problems come back as HttpResponse(0, null, error). */
    public function send(HttpRequest $request): HttpResponse;
}

final class CurlTransport implements HttpTransport
{
    /**
     * The exact curl option set for a request. Public so a contract test can
     * compare it with the options each legacy client used to set.
     */
    public function curlOptions(HttpRequest $r)
    {
        $o = array(
            CURLOPT_URL            => $r->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $r->verify_peer,
        );
        if ($r->headers !== null)         $o[CURLOPT_HTTPHEADER] = $r->headers;
        if ($r->verify_host !== null)     $o[CURLOPT_SSL_VERIFYHOST] = $r->verify_host;
        if ($r->timeout !== null)         $o[CURLOPT_TIMEOUT] = $r->timeout;
        if ($r->connect_timeout !== null) $o[CURLOPT_CONNECTTIMEOUT] = $r->connect_timeout;

        $method = strtoupper($r->method);
        if ($method === 'POST') {
            $o[CURLOPT_POST] = true;
            if ($r->body !== null) $o[CURLOPT_POSTFIELDS] = $r->body;
        } elseif ($method !== 'GET') {
            $o[CURLOPT_CUSTOMREQUEST] = $method;
            if ($r->body !== null) $o[CURLOPT_POSTFIELDS] = $r->body;
        }
        return $o;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, $this->curlOptions($request));
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = (string) curl_error($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            return new HttpResponse(0, null, 'transport exception: ' . get_class($e));
        }
        if ($raw === false) return new HttpResponse(0, null, $err !== '' ? $err : 'curl failed');
        return new HttpResponse($code, (string) $raw, '');
    }
}
