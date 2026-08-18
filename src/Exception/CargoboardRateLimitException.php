<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown on HTTP 429: too many requests.
 *
 * Cargoboard does not document a rate limit or publish its thresholds, so this is a defensive
 * mapping rather than a documented behaviour: if a limit is ever applied (at their edge or by a
 * proxy in front of the API), callers get a distinct type to back off on instead of a generic
 * API error. {@see self::retryAfterSeconds()} reflects the `Retry-After` response header when
 * one is present.
 */
class CargoboardRateLimitException extends CargoboardApiException
{
    private ?int $retryAfter = null;

    /**
     * Set the cool-down parsed from the `Retry-After` header (fluent).
     *
     * @return $this
     */
    public function withRetryAfter(?int $seconds): static
    {
        $this->retryAfter = $seconds !== null && $seconds > 0 ? $seconds : null;
        return $this;
    }

    /** Seconds to wait before retrying, or null when Cargoboard did not say. */
    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfter;
    }
}
