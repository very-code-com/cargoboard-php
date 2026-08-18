<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CostItemType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Enum\TransportType;
use VeryCodeCom\Cargoboard\Enum\TruckType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The answer to `POST /v1/orders` and `POST /v1/quotations/{id}/book`: the shipment is booked.
 *
 * Two identifiers come back and they are not interchangeable in conversation, even though the
 * API accepts either one wherever it takes an `{id}`:
 *
 *  - {@see self::$id} is the internal CUID, e.g. `cm3328igs04jbqt0dluurnxyl`.
 *  - {@see self::$reference} is the **shipment number**, e.g. `10374504`. This is the number
 *    Cargoboard, its network partners and the driver use; put it on your paperwork and quote it
 *    in support tickets.
 *
 * @see https://docs.cargoboard.com/reference/place-an-order
 */
final class OrderResult
{
    /**
     * @param list<CostItem> $costItems Price breakdown; freight plus each surcharge.
     * @param list<Link>     $links     HATEOAS links: cancel, print label/confirmation, track.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly ?Product $product,
        public readonly Price $price,
        public readonly Price $priceStandard,
        public readonly Runtime $runtime,
        public readonly DeliveryWindow $delivery,
        public readonly Co2Emission $co2Emission,
        public readonly array $costItems = [],
        public readonly ?\DateTimeImmutable $pickupOn = null,
        public readonly ?\DateTimeImmutable $pickupAtFrom = null,
        public readonly ?\DateTimeImmutable $pickupAtUntil = null,
        public readonly ?TruckType $truckType = null,
        public readonly ?TransportType $transportType = null,
        /**
         * Ready-made customer tracking link that unlocks the shipment without a captcha.
         * Present on live responses though absent from the published schema; when it is
         * missing, build one with {@see \VeryCodeCom\Cargoboard\Tracking\TrackingUrl}.
         */
        public readonly ?string $platformTrackingUrl = null,
        public readonly array $links = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data  The `data` object of the response.
     * @param list<Link>           $links The response's `links` array.
     */
    public static function fromArray(array $data, array $links = []): self
    {
        $price         = Value::object($data, 'price');
        $priceStandard = Value::object($data, 'priceStandard');
        $runtime       = Value::object($data, 'runtime');
        $delivery      = Value::object($data, 'delivery');
        $co2           = Value::object($data, 'co2Emission');

        return new self(
            id:                  Value::string($data, 'id') ?? '',
            reference:           Value::string($data, 'reference') ?? '',
            product:             Value::enum($data, 'product', Product::class),
            price:               $price !== null ? Price::fromArray($price) : new Price(0.0),
            priceStandard:       $priceStandard !== null ? Price::fromArray($priceStandard) : new Price(0.0),
            runtime:             $runtime !== null ? Runtime::fromArray($runtime) : new Runtime(),
            delivery:            $delivery !== null ? DeliveryWindow::fromArray($delivery) : new DeliveryWindow(),
            co2Emission:         $co2 !== null ? Co2Emission::fromArray($co2) : new Co2Emission(),
            costItems:           array_map(static fn (array $c): CostItem => CostItem::fromArray($c), Value::objectList($data, 'costItems')),
            pickupOn:            Value::dateTime($data, 'pickupOn'),
            pickupAtFrom:        Value::dateTime($data, 'pickupAtFrom'),
            pickupAtUntil:       Value::dateTime($data, 'pickupAtUntil'),
            truckType:           Value::enum($data, 'truckType', TruckType::class),
            transportType:       Value::enum($data, 'transportType', TransportType::class),
            platformTrackingUrl: Value::string($data, 'platformTrackingUrl'),
            links:               $links,
        );
    }

    /** The base freight cost item, i.e. the price before surcharges. */
    public function freightCost(): ?CostItem
    {
        foreach ($this->costItems as $item) {
            if ($item->isFreight()) {
                return $item;
            }
        }

        return null;
    }

    /**
     * All cost items of one type.
     *
     * @return list<CostItem>
     */
    public function costItemsOfType(CostItemType $type): array
    {
        return array_values(array_filter($this->costItems, static fn (CostItem $i): bool => $i->type === $type));
    }

    /**
     * The machine-readable tracking endpoint Cargoboard offers for this order (`orderTrack`).
     * Note that its href addresses the shipment by {@see self::$reference}, not by id.
     */
    public function trackingApiUrl(): ?string
    {
        return Link::findRel($this->links, 'orderTrack')?->href;
    }
}
