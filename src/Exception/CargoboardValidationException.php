<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown when local pre-flight validation fails, before any network call is made.
 *
 * These are the rules from Cargoboard's documentation that can be checked without asking the
 * API: mandatory address fields, pickup/delivery day rules, vehicle payload ceilings, parcel
 * size and weight limits, insurance requiring a goods value, and so on. See
 * {@see \VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator}.
 */
class CargoboardValidationException extends CargoboardException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Cargoboard shipment validation failed: ' . implode('; ', $errors));
    }
}
