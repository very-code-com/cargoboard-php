<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Transport;

/**
 * The raw HTTP response returned by a {@see TransportInterface}.
 *
 * Headers are carried alongside the body because two Cargoboard responses need them: the
 * PDF endpoints (to tell an actual PDF from a JSON error served with the wrong status) and
 * `Retry-After` on a throttled request.
 */
final class TransportResponse
{
    /** @var array<string, string> Response headers, keyed by lower-cased header name. */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        array $headers = [],
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        $this->headers = $normalised;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** A response header by name (case-insensitive), or null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The `Content-Type` without its parameters, e.g. `application/json`, lower-cased. */
    public function contentType(): string
    {
        $contentType = $this->header('content-type') ?? '';
        $semicolon = strpos($contentType, ';');

        return strtolower(trim($semicolon === false ? $contentType : substr($contentType, 0, $semicolon)));
    }

    /** True when the body is a PDF, either by `Content-Type` or by its magic bytes. */
    public function isPdf(): bool
    {
        return $this->contentType() === 'application/pdf' || str_starts_with($this->body, '%PDF-');
    }

    /** `Retry-After` in seconds, when present and expressed as a delay rather than a date. */
    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('retry-after');

        if ($value === null || !ctype_digit(trim($value))) {
            return null;
        }

        return (int) trim($value);
    }
}
