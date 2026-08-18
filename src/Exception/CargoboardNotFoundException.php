<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown on HTTP 404: the requested order, quotation, invoice or UN number does not exist
 * (or does not belong to the authenticated customer).
 *
 * Cargoboard also answers 404 for an unknown URL path, in which case the message reads
 * `Cannot GET /v1/...`.
 */
class CargoboardNotFoundException extends CargoboardApiException
{
}
