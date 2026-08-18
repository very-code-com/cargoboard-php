<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Transport;

use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;

/**
 * HTTP transport contract.
 *
 * Implement this to swap the default cURL transport for a PSR-18 client adapter, a transport
 * that adds retries or circuit breaking, or a scripted test double. The client never talks to
 * cURL directly, so a fake here is enough to test everything above it without a network.
 */
interface TransportInterface
{
    /**
     * Send the request and return the raw HTTP response.
     *
     * Implementations must not throw on HTTP error statuses; those are the client's business.
     * Only genuine transport failures belong here.
     *
     * @throws CargoboardTransportException on network errors, SSL failures, or timeouts.
     */
    public function send(TransportRequest $request): TransportResponse;
}
