<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Support;

use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;
use VeryCodeCom\Cargoboard\Transport\TransportInterface;
use VeryCodeCom\Cargoboard\Transport\TransportRequest;
use VeryCodeCom\Cargoboard\Transport\TransportResponse;

/**
 * A scripted transport: hands back queued responses in order and records what it was asked to
 * send, so the whole client can be exercised without touching the network.
 *
 * This is the pattern the library is designed for - {@see TransportInterface} exists precisely
 * so that a fake can be dropped in - and it is what the examples show for testing an
 * integration built on top of this client.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<TransportRequest> Every request the client made, in order. */
    public array $requests = [];

    /** @var list<TransportResponse|\Throwable> */
    private array $queue;

    /** @param list<TransportResponse|\Throwable> $queue Responses to return, or throwables to raise. */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /** A transport that answers every call with the same JSON body. */
    public static function json(string $body, int $status = 200): self
    {
        return new self([new TransportResponse($status, $body, ['content-type' => 'application/json'])]);
    }

    /** A transport that answers with a PDF stream. */
    public static function pdf(string $body = "%PDF-1.4\nfake", int $status = 200): self
    {
        return new self([new TransportResponse($status, $body, ['content-type' => 'application/pdf'])]);
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new CargoboardTransportException(sprintf(
                'FakeTransport ran out of scripted responses at request #%d (%s %s).',
                count($this->requests),
                $request->method,
                $request->url,
            ));
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** The most recent request, for assertions about what went out. */
    public function lastRequest(): ?TransportRequest
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }

    /**
     * The decoded JSON body of the most recent request.
     *
     * @return array<string, mixed>
     */
    public function lastBody(): array
    {
        $body = $this->lastRequest()?->body ?? '';

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
