<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * Where a tracking event happened, and in what capacity.
 *
 * `role` names the part the location plays in the transport - Cargoboard's examples list
 * `shippingPartner`, `deliveringPartner`, `shipper` and `consignee` - and `depotId` identifies
 * the depot or hub within the CargoLine network. The value set is open, so `role` stays a string.
 */
final class TrackingLocation
{
    public function __construct(
        public readonly ?string $role = null,
        public readonly ?string $depotId = null,
        public readonly ?string $name = null,
        public readonly ?TrackingLocationAddress $address = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $address = Value::object($data, 'address');

        return new self(
            role:    Value::string($data, 'role'),
            depotId: Value::string($data, 'depotId'),
            name:    Value::string($data, 'name'),
            address: $address !== null ? TrackingLocationAddress::fromArray($address) : null,
        );
    }
}
