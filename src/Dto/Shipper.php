<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\LoadingType;
use VeryCodeCom\Cargoboard\Internal\Json\DateFormat;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The pickup side of a shipment: who the goods are collected from, when, and how.
 *
 * `name` is optional for a quotation and mandatory for a booking. There are two ways to say
 * when to collect, and they are mutually exclusive in spirit:
 *
 *   - `pickupOn` for a whole day, or
 *   - `pickupAtFrom` + `pickupAtUntil` for a window.
 *
 * If all three are sent, their date part must match. Pickups only run Monday to Friday.
 *
 * @see https://docs.cargoboard.com/reference/address-fields
 */
final class Shipper
{
    /** `YYYY-MM-DD`, or null. */
    public readonly ?string $pickupOn;
    /** ISO-8601 timestamp, or null. */
    public readonly ?string $pickupAtFrom;
    /** ISO-8601 timestamp, or null. */
    public readonly ?string $pickupAtUntil;

    /**
     * @param string|\DateTimeInterface|null $pickupOn      Collection day; the simple case.
     * @param string|\DateTimeInterface|null $pickupAtFrom  Start of the collection window.
     * @param string|\DateTimeInterface|null $pickupAtUntil End of the collection window.
     * @param bool|null $wantsContactBeforePickup Cargoboard calls ahead to agree on a slot.
     * @param bool|null $wantsTailLiftTruck       Collection needs a tail lift on the vehicle.
     * @param string|null $freeTextForPickup      Opening hours, break times, access notes.
     * @param string|null $reference              Reference the driver quotes when announcing the pickup.
     */
    public function __construct(
        public readonly Address $address,
        public readonly ?string $name = null,
        public readonly ?ContactPerson $contactPerson = null,
        public readonly ?NeutralData $neutralData = null,
        string|\DateTimeInterface|null $pickupOn = null,
        string|\DateTimeInterface|null $pickupAtFrom = null,
        string|\DateTimeInterface|null $pickupAtUntil = null,
        public readonly ?bool $wantsContactBeforePickup = null,
        public readonly ?bool $wantsTailLiftTruck = null,
        public readonly ?LoadingType $loadingType = null,
        public readonly ?string $freeTextForPickup = null,
        public readonly ?string $reference = null,
    ) {
        $this->pickupOn      = DateFormat::date($pickupOn);
        $this->pickupAtFrom  = DateFormat::dateTime($pickupAtFrom);
        $this->pickupAtUntil = DateFormat::dateTime($pickupAtUntil);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'name'                     => $this->name,
            'address'                  => $this->address->toArray(),
            'contactPerson'            => $this->contactPerson?->toArray(),
            'neutralData'              => $this->neutralData?->toArray(),
            'pickupOn'                 => $this->pickupOn,
            'pickupAtFrom'             => $this->pickupAtFrom,
            'pickupAtUntil'            => $this->pickupAtUntil,
            'wantsContactBeforePickup' => $this->wantsContactBeforePickup,
            'wantsTailLiftTruck'       => $this->wantsTailLiftTruck,
            'loadingType'              => $this->loadingType?->value,
            'freeTextForPickup'        => $this->freeTextForPickup,
            'reference'                => $this->reference,
        ];

        return array_filter($data, static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $address = Value::object($data, 'address');
        $contact = Value::object($data, 'contactPerson');
        $neutral = Value::object($data, 'neutralData');

        return new self(
            address:                  $address !== null ? Address::fromArray($address) : new Address('', CountryCode::DE),
            name:                     Value::string($data, 'name'),
            contactPerson:            $contact !== null ? ContactPerson::fromArray($contact) : null,
            neutralData:              $neutral !== null ? NeutralData::fromArray($neutral) : null,
            pickupOn:                 Value::string($data, 'pickupOn'),
            pickupAtFrom:             Value::string($data, 'pickupAtFrom'),
            pickupAtUntil:            Value::string($data, 'pickupAtUntil'),
            wantsContactBeforePickup: Value::bool($data, 'wantsContactBeforePickup'),
            wantsTailLiftTruck:       Value::bool($data, 'wantsTailLiftTruck'),
            loadingType:              Value::enum($data, 'loadingType', LoadingType::class),
            freeTextForPickup:        Value::string($data, 'freeTextForPickup'),
            reference:                Value::string($data, 'reference'),
        );
    }

    /** The collection day, taken from `pickupOn` or from the start of the window. */
    public function pickupDate(): ?\DateTimeImmutable
    {
        return DateFormat::parse($this->pickupOn ?? $this->pickupAtFrom);
    }

    /** True when any of the three pickup date fields is set. */
    public function hasPickupDate(): bool
    {
        return $this->pickupOn !== null || $this->pickupAtFrom !== null || $this->pickupAtUntil !== null;
    }
}
