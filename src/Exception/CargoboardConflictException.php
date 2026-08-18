<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown on HTTP 409: the request was understood but conflicts with the current state.
 *
 * Documented cases:
 *  - an order can no longer be cancelled, because it has already been collected or cancelled;
 *  - "Authorized user is not a customer", i.e. the key authenticates but is not bound to a
 *    customer account that may quote or book;
 *  - an invoice PDF could not be produced.
 *
 * Retrying the same call unchanged will fail again; the caller has to react to the state.
 */
class CargoboardConflictException extends CargoboardApiException
{
}
