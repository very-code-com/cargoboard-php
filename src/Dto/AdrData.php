<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * ADR master data for one UN number, from `GET /v1/dangerous-goods/un-numbers/{unNumber}`.
 *
 * This endpoint exists so that you do **not** have to keep your own dangerous-goods database:
 * look the UN number up, and Cargoboard returns the proper shipping name, hazard class,
 * packing group, tunnel restriction and the rest, ready to be turned into a shipment
 * declaration with {@see self::toDangerousGood()}.
 *
 * **Two response shapes.** Cargoboard's documentation contradicts itself here, and this DTO
 * accepts either:
 *
 *  - the OpenAPI schema documents `unNo`, `substanceName`, `riskMain`, `packagingGroup`,
 *    `isLimitedQuantityEligible`, ... (the shipment-declaration vocabulary);
 *  - the worked example on the Dangerous Goods page shows `unNumber`, `properShippingName`,
 *    `adrClass`, `packingGroup`, `labels: {mainRisk, subRisks}`, `flags: {limitedQuantity, ...}`.
 *
 * Fields are read from whichever of the two is present, so the DTO keeps working whichever one
 * a given deployment serves. The extra fields that only the example carries
 * ({@see self::$packagingInstructions}, {@see self::$generalSpecialRegulations},
 * {@see self::$limitedQuantityCode}, {@see self::$exemptedQuantityType}, {@see self::$adrRelease})
 * are parsed when present and stay null otherwise.
 *
 * @see https://docs.cargoboard.com/reference/dangerous-goods
 * @see https://docs.cargoboard.com/reference/get-dangerous-good-adr-data-by-un-number
 */
final class AdrData
{
    /**
     * @param list<string> $subRisks Additional hazard labels, e.g. ["4.1", "6.1"].
     */
    public function __construct(
        /** UN number without the "UN" prefix, e.g. "3480". */
        public readonly string $unNo,
        /** Proper shipping name, e.g. "LITHIUM-IONEN-BATTERIEN". */
        public readonly ?string $substanceName = null,
        /** Main hazard class/label, e.g. "9A" or "9". */
        public readonly ?string $riskMain = null,
        public readonly array $subRisks = [],
        public readonly ?string $classificationCode = null,
        /** "I", "II" or "III"; null for entries without a packing group. */
        public readonly ?string $packagingGroup = null,
        public readonly ?string $transportCategory = null,
        public readonly ?string $tunnelRestriction = null,
        public readonly ?string $adrVersion = null,
        /** Internal ADR release identifier, e.g. "012"; example-shape responses only. */
        public readonly ?string $adrRelease = null,
        public readonly ?string $technicalNameForNotOtherwiseSpecifiedSubstances = null,
        /** Transport-category multiplier for the 1000-points calculation. */
        public readonly ?int $pointsMultiplier = null,
        public readonly ?bool $isHighConsequencesDangerousGood = null,
        /** May be shipped as an exempted quantity (EQ). */
        public readonly ?bool $isExemptedQuantityEligible = null,
        /** May be shipped as a limited quantity (LQ). */
        public readonly ?bool $isLimitedQuantityEligible = null,
        /** LQ code, e.g. "LQ00"; example-shape responses only. */
        public readonly ?string $limitedQuantityCode = null,
        public readonly ?string $limitedQuantityQuantityText = null,
        /** EQ code, e.g. "E0"; example-shape responses only. */
        public readonly ?string $exemptedQuantityType = null,
        /** Packing instruction codes, e.g. "P903 P908 ..."; example-shape responses only. */
        public readonly ?string $packagingInstructions = null,
        /** Special provision numbers, e.g. "188 230 310 ..."; example-shape responses only. */
        public readonly ?string $generalSpecialRegulations = null,
        public readonly ?bool $specialRegulation274 = null,
        public readonly ?bool $specialRegulation61 = null,
        public readonly ?bool $specialRegulation640 = null,
        public readonly ?bool $notOtherwiseSpecifiedRequired = null,
        public readonly ?bool $allowedInCentralCrossDock = null,
        public readonly ?bool $allowedOnLineHaul = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $labels = Value::object($data, 'labels') ?? [];
        $flags  = Value::object($data, 'flags') ?? [];

        $subRisks = Value::stringList($labels, 'subRisks');
        if ($subRisks === []) {
            foreach (['riskAdditional1', 'riskAdditional2', 'riskAdditional3'] as $key) {
                $risk = Value::string($data, $key);
                if ($risk !== null) {
                    $subRisks[] = $risk;
                }
            }
        }

        return new self(
            unNo:               Value::string($data, 'unNo') ?? Value::string($data, 'unNumber') ?? '',
            substanceName:      Value::string($data, 'substanceName') ?? Value::string($data, 'properShippingName'),
            riskMain:           Value::string($data, 'riskMain') ?? Value::string($labels, 'mainRisk') ?? Value::string($data, 'adrClass'),
            subRisks:           $subRisks,
            classificationCode: Value::string($data, 'classificationCode'),
            packagingGroup:     Value::string($data, 'packagingGroup') ?? Value::string($data, 'packingGroup'),
            transportCategory:  Value::string($data, 'transportCategory'),
            tunnelRestriction:  Value::string($data, 'tunnelRestriction'),
            adrVersion:         Value::string($data, 'adrVersion'),
            adrRelease:         Value::string($data, 'adrRelease'),
            technicalNameForNotOtherwiseSpecifiedSubstances: Value::string($data, 'technicalNameForNotOtherwiseSpecifiedSubstances'),
            pointsMultiplier:   Value::int($data, 'pointsMultiplier'),
            isHighConsequencesDangerousGood: Value::bool($data, 'isHighConsequencesDangerousGood')
                ?? Value::bool($flags, 'highConsequenceDangerousGoods'),
            isExemptedQuantityEligible: Value::bool($data, 'isExemptedQuantityEligible')
                ?? Value::bool($flags, 'exemptedQuantity'),
            isLimitedQuantityEligible:  Value::bool($data, 'isLimitedQuantityEligible')
                ?? Value::bool($flags, 'limitedQuantity'),
            limitedQuantityCode:         Value::string($data, 'limitedQuantityCode'),
            limitedQuantityQuantityText: Value::string($data, 'limitedQuantityQuantityText'),
            exemptedQuantityType:        Value::string($data, 'exemptedQuantityType'),
            packagingInstructions:       Value::string($data, 'packagingInstructions'),
            generalSpecialRegulations:   Value::string($data, 'generalSpecialRegulations'),
            specialRegulation274:          Value::bool($flags, 'specialRegulation274'),
            specialRegulation61:           Value::bool($flags, 'specialRegulation61'),
            specialRegulation640:          Value::bool($flags, 'specialRegulation640'),
            notOtherwiseSpecifiedRequired: Value::bool($flags, 'notOtherwiseSpecifiedRequired'),
            allowedInCentralCrossDock:     Value::bool($flags, 'allowedInCentralCrossDock'),
            allowedOnLineHaul:             Value::bool($flags, 'allowedOnLineHaul'),
        );
    }

    /**
     * Turn this master data into a shipment declaration, filling in only the quantities that
     * are specific to what you are actually shipping. Everything classification-related is
     * carried over, so a booking needs no ADR lookup table of your own:
     *
     *   $adr  = $client->fetchAdrData('3480');
     *   $line = new Line(..., dangerousGoods: [
     *       $adr->toDangerousGood(quantity: 1, weightGross: 11.0, weightNetOrVolume: 9.0, packageType: 'Kiste'),
     *   ]);
     *
     * `pointsTotal` is computed from the transport-category multiplier and the net quantity
     * when both are known.
     */
    public function toDangerousGood(
        ?int $quantity = null,
        ?float $weightGross = null,
        ?float $weightNetOrVolume = null,
        ?string $weightNetOrVolumeUnit = 'NGW',
        ?string $packageType = null,
        ?int $packageQuantity = null,
        ?bool $isLimitedQuantity = null,
        ?bool $isExemptedQuantity = null,
    ): DangerousGood {
        $pointsTotal = null;
        if ($this->pointsMultiplier !== null && $weightNetOrVolume !== null) {
            $pointsTotal = (int) round($this->pointsMultiplier * $weightNetOrVolume);
        }

        return new DangerousGood(
            unNo:                  $this->unNo,
            substanceName:         $this->substanceName ?? '',
            packageType:           $packageType,
            packageQuantity:       $packageQuantity,
            quantity:              $quantity,
            weightGross:           $weightGross,
            weightNetOrVolume:     $weightNetOrVolume,
            weightNetOrVolumeUnit: $weightNetOrVolumeUnit,
            riskMain:              $this->riskMain,
            riskAdditional1:       $this->subRisks[0] ?? null,
            riskAdditional2:       $this->subRisks[1] ?? null,
            riskAdditional3:       $this->subRisks[2] ?? null,
            pointsTotal:           $pointsTotal,
            pointsMultiplier:      $this->pointsMultiplier,
            adrVersion:            $this->adrVersion,
            classificationCode:    $this->classificationCode,
            packagingGroup:        $this->packagingGroup,
            transportCategory:     $this->transportCategory,
            tunnelRestriction:     $this->tunnelRestriction,
            isExemptedQuantity:    $isExemptedQuantity,
            isLimitedQuantity:     $isLimitedQuantity,
            isHighConsequencesDangerousGood: $this->isHighConsequencesDangerousGood,
            technicalNameForNotOtherwiseSpecifiedSubstances: $this->technicalNameForNotOtherwiseSpecifiedSubstances,
        );
    }

    /**
     * The packing instruction codes as a list, e.g. ["P903", "P908", ...].
     *
     * @return list<string>
     */
    public function packagingInstructionList(): array
    {
        if ($this->packagingInstructions === null) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($this->packagingInstructions)) ?: []));
    }

    /**
     * The special provision numbers as a list, e.g. ["188", "230", ...].
     *
     * @return list<string>
     */
    public function specialRegulationList(): array
    {
        if ($this->generalSpecialRegulations === null) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($this->generalSpecialRegulations)) ?: []));
    }

    /** True when special provision 188 (small lithium cells/batteries) applies to this entry. */
    public function hasSpecialProvision188(): bool
    {
        return in_array('188', $this->specialRegulationList(), true);
    }
}
