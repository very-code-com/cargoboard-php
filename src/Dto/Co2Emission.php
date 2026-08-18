<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The carbon footprint Cargoboard calculated for the shipment.
 *
 * Read the three fields carefully, they are not what the names suggest: `value` + `unit` are
 * the emissions themselves (e.g. 33.88 KG of CO2), while `amount` is the **money** charged as
 * the climate contribution for offsetting them, in the price's currency.
 */
final class Co2Emission
{
    public function __construct(
        /** Climate contribution charged, as money. */
        public readonly ?float $amount = null,
        /** Emissions produced, in {@see self::$unit}. */
        public readonly ?float $value = null,
        /** Unit of {@see self::$value}, e.g. "KG". */
        public readonly ?string $unit = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: Value::float($data, 'amount'),
            value:  Value::float($data, 'value'),
            unit:   Value::string($data, 'unit'),
        );
    }

    /** e.g. "33.88 KG". */
    public function __toString(): string
    {
        if ($this->value === null) {
            return 'unknown';
        }

        return number_format($this->value, 2, '.', '') . ' ' . ($this->unit ?? '');
    }
}
