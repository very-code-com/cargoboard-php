<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Validation;

/**
 * Which set of rules {@see \VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator} applies.
 *
 * The request body of a quotation and of a booking is identical, but what Cargoboard *requires*
 * in it is not: a quotation prices a lane from a post code and a country, while a booking has
 * to be executable, so it additionally needs names, streets and a pickup date. Validating a
 * booking-shaped payload against quotation rules would let those omissions through to a 422.
 */
enum ValidationMode: string
{
    /** Rules for `POST /v1/quotations`: enough detail to price the shipment. */
    case Quotation = 'quotation';

    /** Rules for `POST /v1/orders` and `POST /v1/quotations/{id}/book`: enough to drive there. */
    case Order = 'order';

    /** True when the stricter booking rules apply. */
    public function requiresFullAddress(): bool
    {
        return $this === self::Order;
    }
}
