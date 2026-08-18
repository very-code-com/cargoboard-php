<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The VAT portion of a cost item (`pricePartVat`), with the rate that produced it.
 *
 * Present on live quotation and order responses but absent from Cargoboard's published schema,
 * so it is parsed opportunistically and stays null when the API does not send it.
 */
final class VatPart
{
    public function __construct(
        public readonly float $amount,
        /** VAT rate in percent, e.g. 19. */
        public readonly ?float $percentage = null,
        public readonly string $currency = 'EUR',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            amount:     Value::float($data, 'amount') ?? 0.0,
            percentage: Value::float($data, 'percentage'),
            currency:   Value::string($data, 'currency') ?? 'EUR',
        );
    }
}
