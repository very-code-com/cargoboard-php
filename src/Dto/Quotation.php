<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\Incoterm;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Enum\QuotationStatus;
use VeryCodeCom\Cargoboard\Enum\TransportType;
use VeryCodeCom\Cargoboard\Enum\TruckType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A stored quotation, as returned by `GET /v1/quotations` and `GET /v1/quotations/{id}`.
 *
 * The counterpart of {@see Order} on the pricing side, and shaped the same way: the original
 * request echoed back, plus what Cargoboard derived from it. When the quotation has been
 * booked, {@see self::$orderId} points at the resulting order.
 *
 * @see https://docs.cargoboard.com/reference/get-quotations
 */
final class Quotation
{
    /**
     * @param list<OrderLine>     $lines
     * @param list<OrderCostItem> $costItems
     */
    public function __construct(
        public readonly string $id,
        public readonly ?Product $product,
        public readonly Shipper $shipper,
        public readonly Consignee $consignee,
        public readonly array $lines = [],
        public readonly ?QuotationStatus $status = null,
        public readonly ?TransportType $transportType = null,
        public readonly ?TruckType $truckType = null,
        /** Set once this quotation has been turned into an order. */
        public readonly ?string $orderId = null,
        public readonly ?string $customerId = null,
        public readonly ?string $customerOrderCode = null,
        public readonly ?string $couponCode = null,
        public readonly ?float $sequence = null,
        public readonly ?Incoterm $incoterm = null,
        public readonly ?bool $wantsExportDeclaration = null,
        public readonly ?bool $wantsClimateContribution = null,
        public readonly ?bool $wantsInsurance = null,
        public readonly ?float $valueOfGoodsAmount = null,
        public readonly ?string $valueOfGoodsCurrency = null,
        public readonly ?float $priceAmount = null,
        public readonly ?string $priceCurrency = null,
        public readonly ?float $priceAmountStandard = null,
        public readonly ?string $priceCurrencyStandard = null,
        public readonly ?float $actualPrice = null,
        public readonly ?float $actualPriceStandard = null,
        public readonly ?float $actualCost = null,
        public readonly ?float $shipmentCost = null,
        public readonly ?float $runtimeDaysMin = null,
        public readonly ?float $runtimeDaysMax = null,
        public readonly ?float $linesWeight = null,
        public readonly ?float $linesVolume = null,
        public readonly ?float $linesPalletBays = null,
        public readonly ?float $linesLoadingMeter = null,
        public readonly ?float $co2EmissionAmount = null,
        public readonly ?float $co2EmissionValue = null,
        public readonly ?string $co2EmissionUnit = null,
        public readonly ?string $shippingPartner = null,
        public readonly ?string $deliveringPartner = null,
        public readonly ?string $domain = null,
        public readonly ?string $quotationIdEikona = null,
        public readonly ?\DateTimeImmutable $placedAt = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly array $costItems = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $shipper   = Value::object($data, 'shipper');
        $consignee = Value::object($data, 'consignee');

        return new self(
            id:                       Value::string($data, 'id') ?? '',
            product:                  Value::enum($data, 'product', Product::class),
            shipper:                  $shipper !== null ? Shipper::fromArray($shipper) : new Shipper(new Address('', CountryCode::DE)),
            consignee:                $consignee !== null ? Consignee::fromArray($consignee) : new Consignee(new Address('', CountryCode::DE)),
            lines:                    array_map(static fn (array $l): OrderLine => OrderLine::fromArray($l), Value::objectList($data, 'lines')),
            status:                   Value::enum($data, 'status', QuotationStatus::class),
            transportType:            Value::enum($data, 'transportType', TransportType::class),
            truckType:                Value::enum($data, 'truckType', TruckType::class),
            orderId:                  Value::string($data, 'orderId'),
            customerId:               Value::string($data, 'customerId'),
            customerOrderCode:        Value::string($data, 'customerOrderCode'),
            couponCode:               Value::string($data, 'couponCode'),
            sequence:                 Value::float($data, 'sequence'),
            incoterm:                 Value::enum($data, 'incoterm', Incoterm::class),
            wantsExportDeclaration:   Value::bool($data, 'wantsExportDeclaration'),
            wantsClimateContribution: Value::bool($data, 'wantsClimateContribution'),
            wantsInsurance:           Value::bool($data, 'wantsInsurance'),
            valueOfGoodsAmount:       Value::float($data, 'valueOfGoodsAmount'),
            valueOfGoodsCurrency:     Value::string($data, 'valueOfGoodsCurrency'),
            priceAmount:              Value::float($data, 'priceAmount'),
            priceCurrency:            Value::string($data, 'priceCurrency'),
            priceAmountStandard:      Value::float($data, 'priceAmountStandard'),
            priceCurrencyStandard:    Value::string($data, 'priceCurrencyStandard'),
            actualPrice:              Value::float($data, 'actualPrice'),
            actualPriceStandard:      Value::float($data, 'actualPriceStandard'),
            actualCost:               Value::float($data, 'actualCost'),
            shipmentCost:             Value::float($data, 'shipmentCost'),
            runtimeDaysMin:           Value::float($data, 'runtimeDaysMin'),
            runtimeDaysMax:           Value::float($data, 'runtimeDaysMax'),
            linesWeight:              Value::float($data, 'linesWeight'),
            linesVolume:              Value::float($data, 'linesVolume'),
            linesPalletBays:          Value::float($data, 'linesPalletBays'),
            linesLoadingMeter:        Value::float($data, 'linesLoadingMeter'),
            co2EmissionAmount:        Value::float($data, 'co2EmissionAmount'),
            co2EmissionValue:         Value::float($data, 'co2EmissionValue'),
            co2EmissionUnit:          Value::string($data, 'co2EmissionUnit'),
            shippingPartner:          Value::string($data, 'shippingPartner'),
            deliveringPartner:        Value::string($data, 'deliveringPartner'),
            domain:                   Value::string($data, 'domain'),
            quotationIdEikona:        Value::string($data, 'quotationIdEikona'),
            placedAt:                 Value::dateTime($data, 'placedAt'),
            createdAt:                Value::dateTime($data, 'createdAt'),
            updatedAt:                Value::dateTime($data, 'updatedAt'),
            costItems:                array_map(static fn (array $c): OrderCostItem => OrderCostItem::fromArray($c), Value::objectList($data, 'costItems')),
        );
    }

    /** The quoted price, rebuilt from the flattened amount/currency pair. */
    public function price(): ?Price
    {
        return $this->priceAmount !== null
            ? new Price($this->priceAmount, $this->priceCurrency ?? 'EUR')
            : null;
    }

    /** Transit time, rebuilt from the flattened min/max pair. */
    public function runtime(): Runtime
    {
        return new Runtime($this->runtimeDaysMin, $this->runtimeDaysMax);
    }

    /** Carbon footprint, rebuilt from the flattened triple. */
    public function co2Emission(): Co2Emission
    {
        return new Co2Emission($this->co2EmissionAmount, $this->co2EmissionValue, $this->co2EmissionUnit);
    }

    /** True once this quotation has been booked. */
    public function isBooked(): bool
    {
        return $this->orderId !== null && $this->orderId !== '';
    }
}
