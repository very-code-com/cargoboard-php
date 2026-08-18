<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The address of a {@see TrackingLocation}, with coordinates when the depot is geocoded.
 *
 * Deliberately not the request-side {@see Address}: the country arrives as a free-form string
 * (a depot can sit outside Cargoboard's 32 bookable countries) and every field is optional.
 */
final class TrackingLocationAddress
{
    public function __construct(
        public readonly ?string $postCode = null,
        public readonly ?string $city = null,
        public readonly ?string $street = null,
        public readonly ?string $countryCode = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            postCode:    Value::string($data, 'postCode'),
            city:        Value::string($data, 'city'),
            street:      Value::string($data, 'street'),
            countryCode: Value::string($data, 'countryCode'),
            latitude:    Value::float($data, 'latitude'),
            longitude:   Value::float($data, 'longitude'),
        );
    }

    /** True when both coordinates are present, e.g. to drop a pin on a map. */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** One-line rendering, e.g. "DE-41061 Mönchengladbach". */
    public function __toString(): string
    {
        $parts = array_filter([
            $this->street,
            trim(($this->countryCode !== null ? $this->countryCode . '-' : '') . ($this->postCode ?? '') . ' ' . ($this->city ?? '')),
        ]);

        return implode(', ', $parts);
    }
}
