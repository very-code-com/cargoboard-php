<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\Incoterm;
use VeryCodeCom\Cargoboard\Enum\OrderStatus;
use VeryCodeCom\Cargoboard\Enum\PaymentMethod;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Enum\RefundType;
use VeryCodeCom\Cargoboard\Enum\ShipmentStatus;
use VeryCodeCom\Cargoboard\Enum\TariffCategory;
use VeryCodeCom\Cargoboard\Enum\TransportType;
use VeryCodeCom\Cargoboard\Enum\TruckType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A stored order, as returned by `GET /v1/orders` and `GET /v1/orders/{id}`.
 *
 * This is the full record rather than the booking receipt: it echoes back everything that was
 * sent (shipper, consignee, lines, options) and adds everything Cargoboard has learnt since -
 * the shipment status, the partners carrying it, the barcodes on each line, the invoices it has
 * been billed on, and the price both as quoted and as actually settled.
 *
 * Note the two parallel status fields: {@see self::$status} is the state of the *booking*
 * (created, cancelled, ...) and {@see self::$shipmentStatus} the state of the *goods*
 * (collected, in transit, delivered).
 *
 * Prices arrive flattened here (`priceAmount` + `priceCurrency` rather than a `price` object),
 * so {@see self::price()} rebuilds the familiar {@see Price} object.
 *
 * @see https://docs.cargoboard.com/reference/get-orders
 */
final class Order
{
    /**
     * @param list<OrderLine>     $lines
     * @param list<OrderCostItem> $costItems
     * @param list<Invoice>       $easybillInvoices Invoices this order has been billed on.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly ?Product $product,
        public readonly Shipper $shipper,
        public readonly Consignee $consignee,
        public readonly array $lines = [],
        public readonly ?OrderStatus $status = null,
        public readonly ?ShipmentStatus $shipmentStatus = null,
        public readonly ?TransportType $transportType = null,
        public readonly ?TruckType $truckType = null,
        public readonly ?string $customerOrderCode = null,
        public readonly ?string $couponCode = null,
        public readonly ?string $customerId = null,
        public readonly ?string $quotationId = null,
        public readonly ?float $sequence = null,
        public readonly ?Incoterm $incoterm = null,
        public readonly ?bool $wantsExportDeclaration = null,
        public readonly ?bool $wantsClimateContribution = null,
        public readonly ?bool $wantsInsurance = null,
        public readonly ?bool $isSupplyingCompanyOrReceivingCustomer = null,
        public readonly ?float $valueOfGoodsAmount = null,
        public readonly ?string $valueOfGoodsCurrency = null,
        public readonly ?float $priceAmount = null,
        public readonly ?string $priceCurrency = null,
        public readonly ?float $priceAmountStandard = null,
        public readonly ?string $priceCurrencyStandard = null,
        /** Price after any post-booking correction; differs from priceAmount when costs changed. */
        public readonly ?float $actualPrice = null,
        public readonly ?float $actualPriceStandard = null,
        public readonly ?float $grossActualPrice = null,
        public readonly ?float $runtimeDaysMin = null,
        public readonly ?float $runtimeDaysMax = null,
        public readonly ?float $linesWeight = null,
        public readonly ?float $linesVolume = null,
        public readonly ?float $linesPalletBays = null,
        public readonly ?float $linesLoadingMeter = null,
        public readonly ?float $co2EmissionAmount = null,
        public readonly ?float $co2EmissionValue = null,
        public readonly ?string $co2EmissionUnit = null,
        public readonly ?PaymentMethod $paymentMethod = null,
        public readonly ?string $paymentProcessId = null,
        public readonly ?RefundType $refundType = null,
        public readonly ?TariffCategory $tariffCategory = null,
        public readonly ?bool $isEasybillInvoicingActive = null,
        public readonly ?bool $isConfirmationNeeded = null,
        public readonly ?bool $isConfirmed = null,
        public readonly ?\DateTimeImmutable $confirmedAt = null,
        public readonly ?bool $isAcceptanceNeeded = null,
        public readonly ?bool $isAccepted = null,
        public readonly ?\DateTimeImmutable $acceptedAt = null,
        public readonly array $easybillInvoices = [],
        /** The network partner collecting the goods. */
        public readonly ?string $shippingPartner = null,
        /** The network partner delivering the goods. */
        public readonly ?string $deliveringPartner = null,
        public readonly ?string $domain = null,
        public readonly ?string $orderKeyEikona = null,
        public readonly ?string $orderIdEikona = null,
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
            reference:                Value::string($data, 'reference') ?? '',
            product:                  Value::enum($data, 'product', Product::class),
            shipper:                  $shipper !== null ? Shipper::fromArray($shipper) : new Shipper(new Address('', CountryCode::DE)),
            consignee:                $consignee !== null ? Consignee::fromArray($consignee) : new Consignee(new Address('', CountryCode::DE)),
            lines:                    array_map(static fn (array $l): OrderLine => OrderLine::fromArray($l), Value::objectList($data, 'lines')),
            status:                   Value::enum($data, 'status', OrderStatus::class),
            shipmentStatus:           Value::enum($data, 'shipmentStatus', ShipmentStatus::class),
            transportType:            Value::enum($data, 'transportType', TransportType::class),
            truckType:                Value::enum($data, 'truckType', TruckType::class),
            customerOrderCode:        Value::string($data, 'customerOrderCode'),
            couponCode:               Value::string($data, 'couponCode'),
            customerId:               Value::string($data, 'customerId'),
            quotationId:              Value::string($data, 'quotationId'),
            sequence:                 Value::float($data, 'sequence'),
            incoterm:                 Value::enum($data, 'incoterm', Incoterm::class),
            wantsExportDeclaration:   Value::bool($data, 'wantsExportDeclaration'),
            wantsClimateContribution: Value::bool($data, 'wantsClimateContribution'),
            wantsInsurance:           Value::bool($data, 'wantsInsurance'),
            isSupplyingCompanyOrReceivingCustomer: Value::bool($data, 'isSupplyingCompanyOrReceivingCustomer'),
            valueOfGoodsAmount:       Value::float($data, 'valueOfGoodsAmount'),
            valueOfGoodsCurrency:     Value::string($data, 'valueOfGoodsCurrency'),
            priceAmount:              Value::float($data, 'priceAmount'),
            priceCurrency:            Value::string($data, 'priceCurrency'),
            priceAmountStandard:      Value::float($data, 'priceAmountStandard'),
            priceCurrencyStandard:    Value::string($data, 'priceCurrencyStandard'),
            actualPrice:              Value::float($data, 'actualPrice'),
            actualPriceStandard:      Value::float($data, 'actualPriceStandard'),
            grossActualPrice:         Value::float($data, 'grossActualPrice'),
            runtimeDaysMin:           Value::float($data, 'runtimeDaysMin'),
            runtimeDaysMax:           Value::float($data, 'runtimeDaysMax'),
            linesWeight:              Value::float($data, 'linesWeight'),
            linesVolume:              Value::float($data, 'linesVolume'),
            linesPalletBays:          Value::float($data, 'linesPalletBays'),
            linesLoadingMeter:        Value::float($data, 'linesLoadingMeter'),
            co2EmissionAmount:        Value::float($data, 'co2EmissionAmount'),
            co2EmissionValue:         Value::float($data, 'co2EmissionValue'),
            co2EmissionUnit:          Value::string($data, 'co2EmissionUnit'),
            paymentMethod:            Value::enum($data, 'paymentMethod', PaymentMethod::class),
            paymentProcessId:         Value::string($data, 'paymentProcessId'),
            refundType:               Value::enum($data, 'refundType', RefundType::class),
            tariffCategory:           Value::enum($data, 'tariffCategory', TariffCategory::class),
            isEasybillInvoicingActive: Value::bool($data, 'isEasybillInvoicingActive'),
            isConfirmationNeeded:     Value::bool($data, 'isConfirmationNeeded'),
            isConfirmed:              Value::bool($data, 'isConfirmed'),
            confirmedAt:              Value::dateTime($data, 'confirmedAt'),
            isAcceptanceNeeded:       Value::bool($data, 'isAcceptanceNeeded'),
            isAccepted:               Value::bool($data, 'isAccepted'),
            acceptedAt:               Value::dateTime($data, 'acceptedAt'),
            easybillInvoices:         array_map(static fn (array $i): Invoice => Invoice::fromArray($i), Value::objectList($data, 'easybillInvoices')),
            shippingPartner:          Value::string($data, 'shippingPartner'),
            deliveringPartner:        Value::string($data, 'deliveringPartner'),
            domain:                   Value::string($data, 'domain'),
            orderKeyEikona:           Value::string($data, 'orderKeyEikona'),
            orderIdEikona:            Value::string($data, 'orderIdEikona'),
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

    /** True once the goods have been delivered. */
    public function isDelivered(): bool
    {
        return $this->shipmentStatus === ShipmentStatus::Delivered;
    }

    /** True when the booking itself has been cancelled. */
    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled || $this->shipmentStatus === ShipmentStatus::Cancelled;
    }

    /**
     * True when the order still needs Cargoboard's confirmation before it is executed. Orders
     * placed with a user token rather than a customer API key start out this way.
     */
    public function needsConfirmation(): bool
    {
        return $this->isConfirmationNeeded === true && $this->isConfirmed !== true;
    }

    /** Total gross weight of all lines, in kilograms. */
    public function totalWeightKg(): float
    {
        return $this->linesWeight ?? array_sum(array_map(static fn (OrderLine $l): float => $l->totalWeightKg(), $this->lines));
    }

    /**
     * Every barcode on the order, flattened across its lines.
     *
     * @return list<string>
     */
    public function barcodes(): array
    {
        $values = [];

        foreach ($this->lines as $line) {
            foreach ($line->barcodeValues() as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
