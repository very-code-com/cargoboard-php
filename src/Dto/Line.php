<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One line of goods in a quotation or order: N identical handling units of one kind.
 *
 * All seven of `content`, `unitQuantity`, `unitPackageType`, `unitLength`, `unitWidth`,
 * `unitHeight` and `unitWeight` are mandatory. **Dimensions are per unit, in centimetres, and
 * the weight is per unit, in kilograms** - not totals for the line. Getting that wrong is the
 * most common source of a price that looks far too high or too low.
 *
 * `isStackable` and `wantsPalletExchange` are optional but worth sending: Cargoboard prices
 * stackable goods better, because they take up less of the truck.
 *
 * Two special shapes use the same DTO:
 *  - a loading-metre block, {@see self::loadingMetres()}, with package type `PARTIE`;
 *  - a whole vehicle, with one of the `DIRECT_*` package types.
 *
 * @see https://docs.cargoboard.com/reference/lines-packagetypes-and-content
 */
final class Line
{
    /**
     * @param string      $content         What the goods are, e.g. "Fork lift accessories". Appears on the paperwork.
     * @param int         $unitQuantity    How many identical units this line covers.
     * @param PackageType $unitPackageType The kind of handling unit.
     * @param int         $unitLength      Length of one unit, in centimetres.
     * @param int         $unitWidth       Width of one unit, in centimetres.
     * @param int         $unitHeight      Height of one unit, in centimetres.
     * @param float       $unitWeight      Weight of one unit, in kilograms.
     * @param bool|null   $isStackable     Units may be stacked on top of each other.
     * @param bool|null   $wantsPalletExchange Pallets are exchanged one for one at pickup/delivery.
     * @param float|null  $additionalEuroPallets Extra euro pallets used as top/side protection.
     * @param float|null  $additionalGitterBoxes Extra wire mesh boxes used as top/side protection.
     * @param string|null $combinedNomenclatureCode Customs tariff (CN) code of the goods, e.g. "1507".
     * @param list<DangerousGood> $dangerousGoods ADR declarations; required when the goods are hazardous.
     */
    public function __construct(
        public readonly string $content,
        public readonly int $unitQuantity,
        public readonly PackageType $unitPackageType,
        public readonly int $unitLength,
        public readonly int $unitWidth,
        public readonly int $unitHeight,
        public readonly float $unitWeight,
        public readonly ?bool $isStackable = null,
        public readonly ?bool $wantsPalletExchange = null,
        public readonly ?float $additionalEuroPallets = null,
        public readonly ?float $additionalGitterBoxes = null,
        public readonly ?string $combinedNomenclatureCode = null,
        public readonly array $dangerousGoods = [],
    ) {
    }

    /**
     * Build a loading-metre (Lademeter/LDM) line: a part load described by the floor space it
     * takes up rather than by individual pallets.
     *
     * The API expresses the loading metres through the length field, so 7.2 LDM is sent as
     * `unitLength: 720`, with the width pinned to the usable width of a truck bed (240 cm).
     *
     * @param float  $loadingMetres Loading metres, e.g. 7.2.
     * @param float  $totalWeightKg Gross weight of the whole block, in kilograms.
     * @param int    $heightCm      250 or 260 for a tractor-trailer, 300 for a Megaliner.
     *
     * @see https://docs.cargoboard.com/reference/how-to-book-lademeter-ldm
     */
    public static function loadingMetres(
        float $loadingMetres,
        float $totalWeightKg,
        string $content = 'goods',
        int $heightCm = 250,
        ?bool $wantsPalletExchange = null,
    ): self {
        return new self(
            content:             $content,
            unitQuantity:        1,
            unitPackageType:     PackageType::LoadingMetres,
            unitLength:          (int) round($loadingMetres * 100),
            unitWidth:           240,
            unitHeight:          $heightCm,
            unitWeight:          $totalWeightKg,
            wantsPalletExchange: $wantsPalletExchange,
        );
    }

    /**
     * Build a whole-vehicle line. The vehicle itself is the "package", so the dimensions
     * describe the load area rather than a handling unit.
     *
     * @param PackageType $vehicle One of the `DIRECT_*` package types.
     *
     * @throws \InvalidArgumentException when the package type is not a vehicle.
     * @see https://docs.cargoboard.com/reference/vantruck-product
     */
    public static function vehicle(
        PackageType $vehicle,
        float $totalWeightKg,
        string $content = 'goods',
        int $lengthCm = 1,
        int $widthCm = 1,
        int $heightCm = 1,
    ): self {
        if (!$vehicle->isVehicle()) {
            throw new \InvalidArgumentException(sprintf(
                'Line::vehicle(): "%s" is not a vehicle package type; use one of the DIRECT_* types.',
                $vehicle->value,
            ));
        }

        return new self(
            content:         $content,
            unitQuantity:    1,
            unitPackageType: $vehicle,
            unitLength:      $lengthCm,
            unitWidth:       $widthCm,
            unitHeight:      $heightCm,
            unitWeight:      $totalWeightKg,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'content'                  => $this->content,
            'unitQuantity'             => $this->unitQuantity,
            'unitPackageType'          => $this->unitPackageType->value,
            'unitLength'               => $this->unitLength,
            'unitWidth'                => $this->unitWidth,
            'unitHeight'               => $this->unitHeight,
            'unitWeight'               => $this->unitWeight,
            'isStackable'              => $this->isStackable,
            'wantsPalletExchange'      => $this->wantsPalletExchange,
            'additionalEuroPallets'    => $this->additionalEuroPallets,
            'additionalGitterBoxes'    => $this->additionalGitterBoxes,
            'combinedNomenclatureCode' => $this->combinedNomenclatureCode,
        ];

        $data = array_filter($data, static fn (mixed $v): bool => $v !== null);

        if ($this->dangerousGoods !== []) {
            $data['dangerousGoods'] = array_map(static fn (DangerousGood $g): array => $g->toArray(), $this->dangerousGoods);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            content:                  Value::string($data, 'content') ?? '',
            unitQuantity:             Value::int($data, 'unitQuantity') ?? 0,
            unitPackageType:          Value::enum($data, 'unitPackageType', PackageType::class) ?? PackageType::Package,
            unitLength:               Value::int($data, 'unitLength') ?? 0,
            unitWidth:                Value::int($data, 'unitWidth') ?? 0,
            unitHeight:               Value::int($data, 'unitHeight') ?? 0,
            unitWeight:               Value::float($data, 'unitWeight') ?? 0.0,
            isStackable:              Value::bool($data, 'isStackable'),
            wantsPalletExchange:      Value::bool($data, 'wantsPalletExchange'),
            additionalEuroPallets:    Value::float($data, 'additionalEuroPallets'),
            additionalGitterBoxes:    Value::float($data, 'additionalGitterBoxes'),
            combinedNomenclatureCode: Value::string($data, 'combinedNomenclatureCode'),
            dangerousGoods:           array_map(
                static fn (array $g): DangerousGood => DangerousGood::fromArray($g),
                Value::objectList($data, 'dangerousGoods'),
            ),
        );
    }

    /** Total weight of the line: unit weight times quantity, in kilograms. */
    public function totalWeightKg(): float
    {
        return $this->unitWeight * $this->unitQuantity;
    }

    /** Volume of one unit, in cubic metres. */
    public function unitVolumeM3(): float
    {
        return ($this->unitLength * $this->unitWidth * $this->unitHeight) / 1_000_000;
    }

    /** Total volume of the line, in cubic metres. */
    public function totalVolumeM3(): float
    {
        return $this->unitVolumeM3() * $this->unitQuantity;
    }

    /**
     * Volumetric ("dimensional") weight of one unit in kilograms, using the parcel divisor of
     * 5000 that Cargoboard applies to parcel shipments. For parcels the higher of physical and
     * volumetric weight is what counts, and both must stay at or below 32 kg.
     */
    public function unitVolumetricWeightKg(): float
    {
        return ($this->unitLength * $this->unitWidth * $this->unitHeight) / 5000;
    }

    /** Loading metres this line is booked as, or null when it is not a `PARTIE` line. */
    public function loadingMetreValue(): ?float
    {
        return $this->unitPackageType->isLoadingMetres() ? $this->unitLength / 100 : null;
    }

    /** True when this line carries at least one ADR declaration. */
    public function hasDangerousGoods(): bool
    {
        return $this->dangerousGoods !== [];
    }

    /**
     * The unit's sides, longest first, in centimetres.
     *
     * @return list<int>
     */
    public function sortedSides(): array
    {
        $sides = [$this->unitLength, $this->unitWidth, $this->unitHeight];
        rsort($sides);

        return $sides;
    }

    /**
     * Girth as parcel carriers measure it: longest side + 2x each of the other two.
     * Cargoboard caps it at 300 cm for parcel shipments.
     */
    public function girthCm(): int
    {
        [$longest, $second, $third] = $this->sortedSides();

        return $longest + 2 * $second + 2 * $third;
    }
}
