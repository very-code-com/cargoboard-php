<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown on HTTP 5xx: the request failed inside Cargoboard rather than because of its contents.
 *
 *  - 500 Internal Server Error: an unhandled error, possibly triggered by the request body.
 *    Cargoboard asks to be sent the offending body (api@cargoboard.com).
 *  - 502 Bad Gateway: their platform is having a problem; they are already aware of it.
 *
 * 502/503/504 are worth retrying with a backoff, 500 usually is not; see {@see self::isRetryable()}.
 */
class CargoboardServerException extends CargoboardApiException
{
    /**
     * True for gateway-level failures (502, 503, 504), which are transient by nature. A plain
     * 500 is reported as not retryable: repeating a request that made the server throw tends to
     * make it throw again.
     */
    public function isRetryable(): bool
    {
        return in_array($this->statusCode, [502, 503, 504], true);
    }
}
