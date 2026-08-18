<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A price with its currency.
 *
 * The schema documents `amount` + `currency` only, but the live top-level `price` of a quotation
 * or order also carries `grossAmount` and `vatAmount`, so both are parsed when present. `amount`
 * is always the **net** figure.
 */
final class Price
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency = 'EUR',
        /** Gross amount including VAT; only present on the top-level price. */
        public readonly ?float $grossAmount = null,
        /** VAT portion of the gross amount; only present on the top-level price. */
        public readonly ?float $vatAmount = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            amount:      Value::float($data, 'amount') ?? 0.0,
            currency:    Value::string($data, 'currency') ?? 'EUR',
            grossAmount: Value::float($data, 'grossAmount'),
            vatAmount:   Value::float($data, 'vatAmount'),
        );
    }

    /** The net amount as an integer number of cents, for money handling that avoids floats. */
    public function amountInCents(): int
    {
        return (int) round($this->amount * 100);
    }

    /** e.g. "90.85 EUR". */
    public function __toString(): string
    {
        return number_format($this->amount, 2, '.', '') . ' ' . $this->currency;
    }
}
