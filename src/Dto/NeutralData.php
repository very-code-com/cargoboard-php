<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A neutral address: what the *other* party gets to see on the paperwork.
 *
 * Used for drop shipping. Put your own company here and the real manufacturer in
 * `shipper.name`/`shipper.address`, and the consignee sees the goods as coming from you. The
 * truck always drives to the real address; the neutral one never appears on a route.
 *
 * Unlike {@see Address}, every field here is mandatory once the block is present at all, and it
 * is not supported for parcel shipments.
 *
 * @see https://docs.cargoboard.com/reference/address-fields
 */
final class NeutralData
{
    public function __construct(
        public readonly string $name,
        public readonly string $street,
        public readonly string $postCode,
        public readonly string $city,
        public readonly CountryCode $countryCode,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'street'      => $this->street,
            'postCode'    => $this->postCode,
            'city'        => $this->city,
            'countryCode' => $this->countryCode->value,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name:        Value::string($data, 'name') ?? '',
            street:      Value::string($data, 'street') ?? '',
            postCode:    Value::string($data, 'postCode') ?? '',
            city:        Value::string($data, 'city') ?? '',
            countryCode: Value::enum($data, 'countryCode', CountryCode::class) ?? CountryCode::DE,
        );
    }
}
