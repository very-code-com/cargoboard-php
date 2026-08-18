<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Transport;

/** A transport-agnostic HTTP request: everything {@see CurlTransport} needs to make one call. */
final class TransportRequest
{
    /**
     * @param 'GET'|'POST' $method
     * @param array<string, string> $headers Request headers, keyed by header name.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $method = 'GET',
        public readonly ?string $body = null,
        public readonly array $headers = [],
        public readonly int $timeout = 30,
        public readonly int $connectTimeout = 10,
        public readonly bool $verifySsl = true,
    ) {
    }

    /**
     * The same request with its credentials masked, safe to put in a log or an exception
     * message. Only the header values change; the URL and body are untouched because
     * Cargoboard never carries the key in either.
     */
    public function redacted(): self
    {
        $headers = $this->headers;

        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), ['x-api-key', 'authorization'], true)) {
                $headers[$name] = strlen($value) > 8 ? substr($value, 0, 4) . '...[redacted]' : '[redacted]';
            }
        }

        return new self(
            url: $this->url,
            method: $this->method,
            body: $this->body,
            headers: $headers,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            verifySsl: $this->verifySsl,
        );
    }
}
