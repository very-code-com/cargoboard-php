<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A line of goods as Cargoboard stores it, returned by the order and quotation list endpoints.
 *
 * It is the request-side {@see Line} plus what Cargoboard computed or attached: the derived
 * volume, pallet bays and loading metres it priced on, and (for orders) the barcodes of the
 * individual handling units.
 *
 * `unitPackageType` is a plain string here rather than a {@see \VeryCodeCom\Cargoboard\Enum\PackageType}:
 * responses use Cargoboard's full internal code list (some 60 codes such as `BU`, `KN`, `RE`),
 * which is a superset of the codes accepted on a request. {@see self::packageType()} maps it
 * back to the enum when it is one of the known ones.
 */
final class OrderLine
{
    /**
     * @param list<DangerousGood> $dangerousGoods
     * @param list<Barcode>       $barcodes One per handling unit; orders only.
     */
    public function __construct(
        public readonly string $content,
        public readonly int $unitQuantity,
        public readonly string $unitPackageType,
        public readonly int $unitLength,
        public readonly int $unitWidth,
        public readonly int $unitHeight,
        public readonly float $unitWeight,
        public readonly ?bool $isStackable = null,
        public readonly ?bool $wantsPalletExchange = null,
        /** Volume Cargoboard calculated for the line, in cubic metres. */
        public readonly ?float $volume = null,
        /** Pallet bays (Stellplätze) the line occupies. */
        public readonly ?float $palletBays = null,
        /** Loading metres the line occupies. */
        public readonly ?float $loadingMeter = null,
        public readonly ?float $additionalEuroPallets = null,
        public readonly ?float $additionalGitterBoxes = null,
        public readonly ?string $combinedNomenclatureCode = null,
        public readonly array $dangerousGoods = [],
        public readonly array $barcodes = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            content:                  Value::string($data, 'content') ?? '',
            unitQuantity:             Value::int($data, 'unitQuantity') ?? 0,
            unitPackageType:          Value::string($data, 'unitPackageType') ?? '',
            unitLength:               Value::int($data, 'unitLength') ?? 0,
            unitWidth:                Value::int($data, 'unitWidth') ?? 0,
            unitHeight:               Value::int($data, 'unitHeight') ?? 0,
            unitWeight:               Value::float($data, 'unitWeight') ?? 0.0,
            isStackable:              Value::bool($data, 'isStackable'),
            wantsPalletExchange:      Value::bool($data, 'wantsPalletExchange'),
            volume:                   Value::float($data, 'volume'),
            palletBays:               Value::float($data, 'palletBays'),
            loadingMeter:             Value::float($data, 'loadingMeter'),
            additionalEuroPallets:    Value::float($data, 'additionalEuroPallets'),
            additionalGitterBoxes:    Value::float($data, 'additionalGitterBoxes'),
            combinedNomenclatureCode: Value::string($data, 'combinedNomenclatureCode'),
            dangerousGoods:           array_map(
                static fn (array $g): DangerousGood => DangerousGood::fromArray($g),
                Value::objectList($data, 'dangerousGoods'),
            ),
            barcodes:                 array_map(
                static fn (array $b): Barcode => Barcode::fromArray($b),
                Value::objectList($data, 'barcodes'),
            ),
        );
    }

    /** The package type as an enum, or null when Cargoboard used an internal-only code. */
    public function packageType(): ?\VeryCodeCom\Cargoboard\Enum\PackageType
    {
        return \VeryCodeCom\Cargoboard\Enum\PackageType::tryFrom($this->unitPackageType);
    }

    /** Total weight of the line, in kilograms. */
    public function totalWeightKg(): float
    {
        return $this->unitWeight * $this->unitQuantity;
    }

    /**
     * The barcode values alone, in the order Cargoboard returned them.
     *
     * @return list<string>
     */
    public function barcodeValues(): array
    {
        $values = [];

        foreach ($this->barcodes as $barcode) {
            if ($barcode->value !== null) {
                $values[] = $barcode->value;
            }
        }

        return $values;
    }

    /** True when this line carries at least one ADR declaration. */
    public function hasDangerousGoods(): bool
    {
        return $this->dangerousGoods !== [];
    }
}
