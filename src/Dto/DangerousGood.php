<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One ADR dangerous-goods declaration inside a shipment line.
 *
 * Everything here comes straight from the ADR (European Agreement concerning the International
 * Carriage of Dangerous Goods by Road) entry for the substance. You do not have to keep your
 * own ADR database to fill it in: {@see \VeryCodeCom\Cargoboard\CargoboardClient::fetchAdrData()}
 * looks a UN number up and {@see \VeryCodeCom\Cargoboard\Dto\AdrData::toDangerousGood()} turns
 * the answer into this DTO, leaving only the quantities to fill in.
 *
 * The 1000-points rule: `pointsTotal` is the transport-category multiplier times the net
 * quantity. Above 1000 points a shipment leaves the "limited quantities per transport unit"
 * exemption and needs a fully placarded vehicle, which Cargoboard prices differently.
 *
 * Dangerous goods are not available on parcel shipments, including LQ and EQ.
 *
 * @see https://docs.cargoboard.com/reference/dangerous-goods
 */
final class DangerousGood
{
    /**
     * @param string      $unNo             UN number without the "UN" prefix, e.g. "3480".
     * @param string      $substanceName    Proper shipping name, e.g. "LITHIUM-IONEN-BATTERIEN".
     * @param string|null $packageType      Packaging description, e.g. "Kiste".
     * @param int|null    $packageQuantity  Number of packages.
     * @param int|null    $quantity         Number of units of the substance.
     * @param float|null  $weightGross      Gross weight in kg.
     * @param float|null  $weightNetOrVolume Net weight in kg, or volume in litres.
     * @param string|null $weightNetOrVolumeUnit Unit of the above, e.g. "NGW" (net gross weight), "L".
     * @param string|null $riskMain         Main hazard label, e.g. "9A".
     * @param int|null    $pointsTotal      Points for the 1000-points calculation.
     * @param int|null    $pointsMultiplier Transport-category multiplier (1, 3, 50 or 0).
     * @param string|null $adrVersion       ADR edition the data follows, e.g. "ADR2023".
     * @param string|null $classificationCode ADR classification code, e.g. "M4".
     * @param string|null $packagingGroup   "I", "II" or "III"; null for entries without one.
     * @param string|null $transportCategory ADR transport category, "0" to "4".
     * @param string|null $tunnelRestriction Tunnel restriction code, e.g. "(E)".
     * @param bool|null   $isExemptedQuantity Shipped as an exempted quantity (EQ).
     * @param bool|null   $isLimitedQuantity  Shipped as a limited quantity (LQ).
     * @param bool|null   $isHighConsequencesDangerousGood Falls under ADR 1.10.3 security rules.
     * @param bool|null   $isSpecialProvision188 Small lithium cell/battery exemption applies.
     * @param string|null $technicalNameForNotOtherwiseSpecifiedSubstances Technical name for N.O.S. entries.
     * @param float|null  $netExplosiveMass Net explosive mass in kg, for class 1 goods.
     */
    public function __construct(
        public readonly string $unNo,
        public readonly string $substanceName,
        public readonly ?string $packageType = null,
        public readonly ?int $packageQuantity = null,
        public readonly ?int $quantity = null,
        public readonly ?float $weightGross = null,
        public readonly ?float $weightNetOrVolume = null,
        public readonly ?string $weightNetOrVolumeUnit = null,
        public readonly ?string $riskMain = null,
        public readonly ?string $riskAdditional1 = null,
        public readonly ?string $riskAdditional2 = null,
        public readonly ?string $riskAdditional3 = null,
        public readonly ?int $pointsTotal = null,
        public readonly ?int $pointsMultiplier = null,
        public readonly ?string $adrVersion = null,
        public readonly ?string $classificationCode = null,
        public readonly ?string $packagingGroup = null,
        public readonly ?string $transportCategory = null,
        public readonly ?string $tunnelRestriction = null,
        public readonly ?bool $isExemptedQuantity = null,
        public readonly ?bool $isLimitedQuantity = null,
        public readonly ?bool $isHighConsequencesDangerousGood = null,
        public readonly ?bool $isSpecialProvision188 = null,
        public readonly ?string $technicalNameForNotOtherwiseSpecifiedSubstances = null,
        public readonly ?float $netExplosiveMass = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'unNo'                            => $this->unNo,
            'substanceName'                   => $this->substanceName,
            'packageType'                     => $this->packageType,
            'packageQuantity'                 => $this->packageQuantity,
            'quantity'                        => $this->quantity,
            'weightGross'                     => $this->weightGross,
            'weightNetOrVolume'               => $this->weightNetOrVolume,
            'weightNetOrVolumeUnit'           => $this->weightNetOrVolumeUnit,
            'riskMain'                        => $this->riskMain,
            'riskAdditional1'                 => $this->riskAdditional1,
            'riskAdditional2'                 => $this->riskAdditional2,
            'riskAdditional3'                 => $this->riskAdditional3,
            'pointsTotal'                     => $this->pointsTotal,
            'pointsMultiplier'                => $this->pointsMultiplier,
            'adrVersion'                      => $this->adrVersion,
            'classificationCode'              => $this->classificationCode,
            'packagingGroup'                  => $this->packagingGroup,
            'transportCategory'               => $this->transportCategory,
            'tunnelRestriction'               => $this->tunnelRestriction,
            'isExemptedQuantity'              => $this->isExemptedQuantity,
            'isLimitedQuantity'               => $this->isLimitedQuantity,
            'isHighConsequencesDangerousGood' => $this->isHighConsequencesDangerousGood,
            'isSpecialProvision188'           => $this->isSpecialProvision188,
            'technicalNameForNotOtherwiseSpecifiedSubstances' => $this->technicalNameForNotOtherwiseSpecifiedSubstances,
            'netExplosiveMass'                => $this->netExplosiveMass,
        ];

        return array_filter($data, static fn (mixed $v): bool => $v !== null);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            unNo:                            Value::string($data, 'unNo') ?? '',
            substanceName:                   Value::string($data, 'substanceName') ?? '',
            packageType:                     Value::string($data, 'packageType'),
            packageQuantity:                 Value::int($data, 'packageQuantity'),
            quantity:                        Value::int($data, 'quantity'),
            weightGross:                     Value::float($data, 'weightGross'),
            weightNetOrVolume:               Value::float($data, 'weightNetOrVolume'),
            weightNetOrVolumeUnit:           Value::string($data, 'weightNetOrVolumeUnit'),
            riskMain:                        Value::string($data, 'riskMain'),
            riskAdditional1:                 Value::string($data, 'riskAdditional1'),
            riskAdditional2:                 Value::string($data, 'riskAdditional2'),
            riskAdditional3:                 Value::string($data, 'riskAdditional3'),
            pointsTotal:                     Value::int($data, 'pointsTotal'),
            pointsMultiplier:                Value::int($data, 'pointsMultiplier'),
            adrVersion:                      Value::string($data, 'adrVersion'),
            classificationCode:              Value::string($data, 'classificationCode'),
            packagingGroup:                  Value::string($data, 'packagingGroup'),
            transportCategory:               Value::string($data, 'transportCategory'),
            tunnelRestriction:               Value::string($data, 'tunnelRestriction'),
            isExemptedQuantity:              Value::bool($data, 'isExemptedQuantity'),
            isLimitedQuantity:               Value::bool($data, 'isLimitedQuantity'),
            isHighConsequencesDangerousGood: Value::bool($data, 'isHighConsequencesDangerousGood'),
            isSpecialProvision188:           Value::bool($data, 'isSpecialProvision188'),
            technicalNameForNotOtherwiseSpecifiedSubstances: Value::string($data, 'technicalNameForNotOtherwiseSpecifiedSubstances'),
            netExplosiveMass:                Value::float($data, 'netExplosiveMass'),
        );
    }

    /**
     * The 1000-points value implied by `pointsMultiplier` and the net quantity, for callers
     * that want to compute it rather than send a pre-calculated `pointsTotal`. Returns null
     * when either input is missing.
     */
    public function calculatePoints(): ?float
    {
        if ($this->pointsMultiplier === null || $this->weightNetOrVolume === null) {
            return null;
        }

        return $this->pointsMultiplier * $this->weightNetOrVolume;
    }

    /** True when this declaration would exceed the ADR 1.1.3.6 "1000 points" threshold. */
    public function exceedsThousandPoints(): bool
    {
        $points = $this->pointsTotal !== null ? (float) $this->pointsTotal : $this->calculatePoints();

        return $points !== null && $points > 1000.0;
    }
}
