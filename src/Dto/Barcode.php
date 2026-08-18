<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One barcode of an order line: Cargoboard issues one per handling unit, so a line with
 * `unitQuantity: 3` carries three of them.
 *
 * These are the numbers printed on the shipment labels and scanned along the way; they only
 * appear once the order exists, i.e. on `GET /v1/orders`, never on a quotation.
 */
final class Barcode
{
    public function __construct(
        public readonly ?string $id = null,
        /** Position of this unit within its line, starting at 1. */
        public readonly ?float $sequence = null,
        /** The scannable value itself. */
        public readonly ?string $value = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id:       Value::string($data, 'id'),
            sequence: Value::float($data, 'sequence'),
            value:    Value::string($data, 'value'),
        );
    }
}
