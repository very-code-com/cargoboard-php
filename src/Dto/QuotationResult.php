<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CostItemType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Enum\TransportType;
use VeryCodeCom\Cargoboard\Enum\TruckType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The answer to `POST /v1/quotations`: a binding price for the shipment you described, with the
 * pickup and delivery dates it is based on.
 *
 * The {@see self::$id} is what turns it into a booking: pass it to
 * {@see \VeryCodeCom\Cargoboard\CargoboardClient::bookQuotation()} and the order is created at
 * this price, without re-pricing.
 *
 * @see https://docs.cargoboard.com/reference/place-a-quotation
 */
final class QuotationResult
{
    /**
     * @param list<CostItem> $costItems Price breakdown; freight plus each surcharge.
     * @param list<Link>     $links     HATEOAS links, including `quotationBook`.
     */
    public function __construct(
        public readonly string $id,
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
            id:            Value::string($data, 'id') ?? '',
            product:       Value::enum($data, 'product', Product::class),
            price:         $price !== null ? Price::fromArray($price) : new Price(0.0),
            priceStandard: $priceStandard !== null ? Price::fromArray($priceStandard) : new Price(0.0),
            runtime:       $runtime !== null ? Runtime::fromArray($runtime) : new Runtime(),
            delivery:      $delivery !== null ? DeliveryWindow::fromArray($delivery) : new DeliveryWindow(),
            co2Emission:   $co2 !== null ? Co2Emission::fromArray($co2) : new Co2Emission(),
            costItems:     array_map(static fn (array $c): CostItem => CostItem::fromArray($c), Value::objectList($data, 'costItems')),
            pickupOn:      Value::dateTime($data, 'pickupOn'),
            pickupAtFrom:  Value::dateTime($data, 'pickupAtFrom'),
            pickupAtUntil: Value::dateTime($data, 'pickupAtUntil'),
            truckType:     Value::enum($data, 'truckType', TruckType::class),
            transportType: Value::enum($data, 'transportType', TransportType::class),
            links:         $links,
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
     * All cost items of one type, e.g. every fuel surcharge line.
     *
     * @return list<CostItem>
     */
    public function costItemsOfType(CostItemType $type): array
    {
        return array_values(array_filter($this->costItems, static fn (CostItem $i): bool => $i->type === $type));
    }

    /** Sum of the breakdown, to reconcile against {@see self::$price} when auditing a quote. */
    public function costItemsTotal(): float
    {
        return array_sum(array_map(static fn (CostItem $i): float => $i->price->amount, $this->costItems));
    }

    /**
     * The URL that books this quotation, as offered by the API itself. The client builds the
     * same URL from {@see self::$id}, so this is only needed when following links verbatim.
     */
    public function bookingUrl(): ?string
    {
        return Link::findRel($this->links, 'quotationBook')?->href;
    }
}
