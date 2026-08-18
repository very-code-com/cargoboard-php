<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A pickup or delivery address.
 *
 * How much of it is mandatory depends on what you are asking for, which is the single most
 * important asymmetry in this API: **a quotation needs only `postCode` + `countryCode`** (plus
 * `city` in practice), while **a booking additionally needs `street` and `city`**. The request
 * body is otherwise identical for both, so the same DTO serves both and
 * {@see \VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator} applies the stricter rules
 * only when an order is being placed.
 *
 * @see https://docs.cargoboard.com/reference/address-fields
 */
final class Address
{
    /**
     * @param string      $postCode    Postal code, e.g. "10115". Kept as a string: leading zeros matter.
     * @param CountryCode $countryCode One of the 32 countries Cargoboard serves.
     * @param string|null $city        Required for a booking; strongly recommended for a quotation.
     * @param string|null $street      Street and house number in one field, e.g. "Examplestreet 12a". Required for a booking.
     */
    public function __construct(
        public readonly string $postCode,
        public readonly CountryCode $countryCode,
        public readonly ?string $city = null,
        public readonly ?string $street = null,
    ) {
    }

    /**
     * Convenience constructor taking the country as a plain string, for callers reading
     * addresses out of a database or a CSV rather than building them by hand.
     *
     * @throws \InvalidArgumentException when the country is outside Cargoboard's service area.
     */
    public static function of(string $postCode, string $countryCode, ?string $city = null, ?string $street = null): self
    {
        return new self($postCode, CountryCode::fromString($countryCode), $city, $street);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'postCode'    => $this->postCode,
            'countryCode' => $this->countryCode->value,
            'city'        => $this->city,
            'street'      => $this->street,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            postCode:    Value::string($data, 'postCode') ?? '',
            countryCode: Value::enum($data, 'countryCode', CountryCode::class) ?? CountryCode::DE,
            city:        Value::string($data, 'city'),
            street:      Value::string($data, 'street'),
        );
    }

    /** One-line rendering, e.g. "Examplestreet 12a, DE-10115 Berlin". */
    public function __toString(): string
    {
        $parts = array_filter([
            $this->street,
            trim($this->countryCode->value . '-' . $this->postCode . ' ' . ($this->city ?? '')),
        ]);

        return implode(', ', $parts);
    }
}
