<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\LoadingType;
use VeryCodeCom\Cargoboard\Internal\Json\DateFormat;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The delivery side of a shipment: who receives the goods, and which delivery services apply.
 *
 * As with {@see Shipper}, `name` is optional for a quotation and mandatory for a booking.
 *
 * Two fields deserve attention:
 *  - `deliveryOn` may only be sent with a FIX product; on any other product Cargoboard rejects it.
 *  - `isPrivateCustomer` must be true for B2C deliveries. It changes both the price (a private
 *    delivery surcharge appears in the breakdown) and how the delivery is run.
 *
 * @see https://docs.cargoboard.com/reference/address-fields
 */
final class Consignee
{
    /** `YYYY-MM-DD`, or null. Only valid together with a FIX product. */
    public readonly ?string $deliveryOn;

    /**
     * @param bool|null $isPrivateCustomer                      Consignee is a private individual (B2C).
     * @param bool|null $wantsContactBeforeDelivery             Cargoboard calls ahead to agree on a slot.
     * @param bool|null $wantsPhoneCallFromDriverBeforeDelivery  Driver calls 30-60 minutes before arriving.
     * @param bool|null $wantsDeliveryWithoutConsigneePresence   Goods may be left without anyone present.
     * @param bool|null $wantsTailLiftTruck                      Delivery needs a tail lift on the vehicle.
     * @param string|\DateTimeInterface|null $deliveryOn         Fixed delivery day; FIX products only.
     */
    public function __construct(
        public readonly Address $address,
        public readonly ?string $name = null,
        public readonly ?ContactPerson $contactPerson = null,
        public readonly ?NeutralData $neutralData = null,
        public readonly ?bool $isPrivateCustomer = null,
        public readonly ?bool $wantsContactBeforeDelivery = null,
        public readonly ?bool $wantsPhoneCallFromDriverBeforeDelivery = null,
        public readonly ?bool $wantsDeliveryWithoutConsigneePresence = null,
        public readonly ?bool $wantsTailLiftTruck = null,
        string|\DateTimeInterface|null $deliveryOn = null,
        public readonly ?DeliveryTimeSlot $deliveryTimeSlot = null,
        public readonly ?LoadingType $unloadingType = null,
        public readonly ?string $freeTextForDelivery = null,
        public readonly ?string $reference = null,
    ) {
        $this->deliveryOn = DateFormat::date($deliveryOn);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'name'                                   => $this->name,
            'address'                                => $this->address->toArray(),
            'contactPerson'                          => $this->contactPerson?->toArray(),
            'neutralData'                            => $this->neutralData?->toArray(),
            'isPrivateCustomer'                      => $this->isPrivateCustomer,
            'wantsContactBeforeDelivery'             => $this->wantsContactBeforeDelivery,
            'wantsPhoneCallFromDriverBeforeDelivery' => $this->wantsPhoneCallFromDriverBeforeDelivery,
            'wantsDeliveryWithoutConsigneePresence'  => $this->wantsDeliveryWithoutConsigneePresence,
            'wantsTailLiftTruck'                     => $this->wantsTailLiftTruck,
            'deliveryOn'                             => $this->deliveryOn,
            'deliveryTimeSlot'                       => $this->deliveryTimeSlot?->toArray(),
            'unloadingType'                          => $this->unloadingType?->value,
            'freeTextForDelivery'                    => $this->freeTextForDelivery,
            'reference'                              => $this->reference,
        ];

        return array_filter($data, static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $address = Value::object($data, 'address');
        $contact = Value::object($data, 'contactPerson');
        $neutral = Value::object($data, 'neutralData');
        $slot    = Value::object($data, 'deliveryTimeSlot');

        return new self(
            address:                                $address !== null ? Address::fromArray($address) : new Address('', CountryCode::DE),
            name:                                   Value::string($data, 'name'),
            contactPerson:                          $contact !== null ? ContactPerson::fromArray($contact) : null,
            neutralData:                            $neutral !== null ? NeutralData::fromArray($neutral) : null,
            isPrivateCustomer:                      Value::bool($data, 'isPrivateCustomer'),
            wantsContactBeforeDelivery:             Value::bool($data, 'wantsContactBeforeDelivery'),
            wantsPhoneCallFromDriverBeforeDelivery: Value::bool($data, 'wantsPhoneCallFromDriverBeforeDelivery'),
            wantsDeliveryWithoutConsigneePresence:  Value::bool($data, 'wantsDeliveryWithoutConsigneePresence'),
            wantsTailLiftTruck:                     Value::bool($data, 'wantsTailLiftTruck'),
            deliveryOn:                             Value::string($data, 'deliveryOn'),
            deliveryTimeSlot:                       $slot !== null ? DeliveryTimeSlot::fromArray($slot) : null,
            unloadingType:                          Value::enum($data, 'unloadingType', LoadingType::class),
            freeTextForDelivery:                    Value::string($data, 'freeTextForDelivery'),
            reference:                              Value::string($data, 'reference'),
        );
    }

    /** The fixed delivery day, when one was requested. */
    public function deliveryDate(): ?\DateTimeImmutable
    {
        return DateFormat::parse($this->deliveryOn);
    }
}
