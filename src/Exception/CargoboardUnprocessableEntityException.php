<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown on HTTP 422: the JSON parsed, but Cargoboard rejected its contents.
 *
 * This is the one error whose body is genuinely useful: `message` is an array with one entry
 * per invalid field, already naming the field path, e.g.
 *
 *   shipper.address.countryCode must be one of the following values: AL, AT, BE, ...
 *
 * Use {@see CargoboardApiException::$errors} for the messages and
 * {@see CargoboardApiException::getFieldNames()} for the field paths alone. Most 422s that a
 * caller can prevent are already caught locally by
 * {@see \VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator} before the request goes out.
 */
class CargoboardUnprocessableEntityException extends CargoboardApiException
{
}
