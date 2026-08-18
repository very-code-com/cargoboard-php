<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown on HTTP 401 and 403, i.e. whenever Cargoboard refuses the API key.
 *
 *  - 401 Unauthorized: no `X-API-KEY` header was sent at all.
 *  - 403 Forbidden:    a key was sent but is not accepted.
 *
 * The single most common cause of a 403 with a key that "should work" is an environment
 * mismatch: Cargoboard issues **separate keys for sandbox and production**, and a production
 * key sent to `api-sandbox.cargoboard.com` is rejected exactly like an invalid one. A key that
 * has not been activated for API access yet fails the same way.
 *
 * Note that the sandbox answers 403 for an absent key too, rather than the documented 401.
 */
class CargoboardAuthException extends CargoboardApiException
{
    /** True for the "no key sent" case, false when a key was sent and rejected. */
    public function isMissingCredentials(): bool
    {
        return $this->statusCode === 401;
    }
}
