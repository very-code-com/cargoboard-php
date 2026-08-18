<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CostItemSubtype;
use VeryCodeCom\Cargoboard\Enum\CostItemType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A cost item as returned by the **list** endpoints (`GET /v1/orders`, `GET /v1/quotations`).
 *
 * Deliberately a separate type from {@see CostItem}, because the shape is genuinely different:
 * the list endpoints flatten the price into `sellPrice` + `currency` + `vat`, and add the
 * invoicing fields (`isOnCustomerInvoice`, `easybillInvoiceId`) that tie the item to a document.
 * Quotation cost items additionally carry Cargoboard's own `cost`.
 */
final class OrderCostItem
{
    public function __construct(
        public readonly string $description,
        public readonly ?CostItemType $type,
        public readonly ?CostItemSubtype $subtype,
        /** Net amount charged to the customer. */
        public readonly ?float $sellPrice = null,
        public readonly string $currency = 'EUR',
        /** VAT amount for this item. */
        public readonly ?float $vat = null,
        /** Whether the item appears on the customer's invoice at all. */
        public readonly ?bool $isOnCustomerInvoice = null,
        /** The invoice this item was billed on, when it has been billed. */
        public readonly ?string $easybillInvoiceId = null,
        public readonly ?float $sequence = null,
        /** Cargoboard's own cost for the item; quotation list only. */
        public readonly ?float $cost = null,
        public readonly ?string $rawType = null,
        public readonly ?string $rawSubtype = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            description:         Value::string($data, 'description') ?? '',
            type:                Value::enum($data, 'type', CostItemType::class),
            subtype:             Value::enum($data, 'subtype', CostItemSubtype::class),
            sellPrice:           Value::float($data, 'sellPrice'),
            currency:            Value::string($data, 'currency') ?? 'EUR',
            vat:                 Value::float($data, 'vat'),
            isOnCustomerInvoice: Value::bool($data, 'isOnCustomerInvoice'),
            easybillInvoiceId:   Value::string($data, 'easybillInvoiceId'),
            sequence:            Value::float($data, 'sequence'),
            cost:                Value::float($data, 'cost'),
            rawType:             Value::string($data, 'type'),
            rawSubtype:          Value::string($data, 'subtype'),
        );
    }

    /** True for the base freight rate, as opposed to a surcharge or a discount. */
    public function isFreight(): bool
    {
        return $this->type === CostItemType::Shipment;
    }

    /** Gross amount (net + VAT), or null when either part is missing. */
    public function grossPrice(): ?float
    {
        if ($this->sellPrice === null) {
            return null;
        }

        return $this->sellPrice + ($this->vat ?? 0.0);
    }
}
