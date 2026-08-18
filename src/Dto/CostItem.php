<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\CostItemSubtype;
use VeryCodeCom\Cargoboard\Enum\CostItemType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One line of the price breakdown returned with a quotation or a freshly placed order.
 *
 * The `description` is human-readable and localised (German on a German account, e.g.
 * "Frachtkosten", "Klimaschutzbeitrag"), so branch on {@see self::$type} rather than on the text.
 *
 * `type` and `subtype` are nullable because Cargoboard sends `null` for `subtype` on most items
 * and may add new types; an unrecognised value parses to null rather than throwing, with the
 * raw string preserved in {@see self::$rawType} / {@see self::$rawSubtype}.
 */
final class CostItem
{
    public function __construct(
        public readonly string $description,
        public readonly ?CostItemType $type,
        public readonly ?CostItemSubtype $subtype,
        public readonly Price $price,
        public readonly ?VatPart $pricePartVat = null,
        /** The `type` string exactly as sent, even when it is not (yet) a known enum case. */
        public readonly ?string $rawType = null,
        /** The `subtype` string exactly as sent, even when it is not (yet) a known enum case. */
        public readonly ?string $rawSubtype = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $price = Value::object($data, 'price');
        $vat   = Value::object($data, 'pricePartVat');

        return new self(
            description:  Value::string($data, 'description') ?? '',
            type:         Value::enum($data, 'type', CostItemType::class),
            subtype:      Value::enum($data, 'subtype', CostItemSubtype::class),
            price:        $price !== null ? Price::fromArray($price) : new Price(0.0),
            pricePartVat: $vat !== null ? VatPart::fromArray($vat) : null,
            rawType:      Value::string($data, 'type'),
            rawSubtype:   Value::string($data, 'subtype'),
        );
    }

    /** True for the base freight rate, as opposed to a surcharge or a discount. */
    public function isFreight(): bool
    {
        return $this->type === CostItemType::Shipment;
    }
}
