<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Internal\Validator;

use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Internal\Json\DateFormat;
use VeryCodeCom\Cargoboard\Validation\ParcelLimits;
use VeryCodeCom\Cargoboard\Validation\ValidationMode;

/**
 * Local pre-flight validation of a {@see ShipmentRequest}, run before any network call.
 *
 * Everything checked here is a rule Cargoboard states in its documentation and would otherwise
 * enforce with an HTTP 422 (or, worse, accept and price wrongly). Catching them locally turns a
 * round-trip into an exception with a field-level message, and keeps a booking from failing
 * halfway through a batch.
 *
 * The rules, by source:
 *
 *  - **Address fields**: a quotation needs post code + country; a booking additionally needs
 *    name, street and city on both sides, plus a pickup date.
 *  - **Pickup and delivery days**: Monday to Friday only. If `pickupOn` and the
 *    `pickupAtFrom`/`pickupAtUntil` window are both sent, their date part must agree.
 *  - **Products**: `deliveryOn` may only be sent with a FIX product.
 *  - **Van/Truck**: every `DIRECT_*` vehicle but the 40-tonne truck must be booked as DIRECT;
 *    the 40-tonne truck as STANDARD or EXPRESS. Each vehicle has a payload ceiling.
 *  - **Loading metres**: a `PARTIE` line is one block, 240 cm wide, 250/260/300 cm high.
 *  - **Parcel**: 32 kg physical and volumetric, 100 cm longest side, 76 cm second side, 300 cm
 *    girth, 20 parcels per pickup location per day, EUR 40 000 insured value, no dangerous
 *    goods, no neutral address, and no EU-to-EU lane that avoids Germany.
 *  - **Insurance**: `wantsInsurance` needs a goods value.
 *  - **Dangerous goods**: a declaration needs at least a UN number and a substance name.
 *
 * Messages are field-path prefixed the way Cargoboard's own 422 messages are
 * (`shipper.address.street`, `lines[0].unitWeight`), so both sources of validation errors read
 * alike in a log.
 *
 * @internal
 */
final class ShipmentValidator
{
    /** Heights Cargoboard documents for a loading-metre block: tractor-trailer or Megaliner. */
    private const LDM_HEIGHTS_CM = [250, 260, 300];

    /** Usable width of a truck bed, in centimetres; fixed for loading-metre bookings. */
    private const LDM_WIDTH_CM = 240;

    /**
     * Validate a shipment request.
     *
     * @return list<string> Validation error messages; an empty array means locally valid.
     */
    public function validate(ShipmentRequest $request, ValidationMode $mode, bool $parcelMode = false): array
    {
        $errors = [];

        $this->validateShipper($request->shipper, $mode, $errors);
        $this->validateConsignee($request->consignee, $mode, $errors);
        $this->validateDates($request, $errors);
        $this->validateLines($request, $errors);
        $this->validateProductAndVehicle($request, $errors);
        $this->validateInsuranceAndValue($request, $errors);

        if ($parcelMode) {
            $this->validateParcel($request, $errors);
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function validateShipper(Shipper $shipper, ValidationMode $mode, array &$errors): void
    {
        if ($shipper->address->postCode === '') {
            $errors[] = 'shipper.address.postCode is required.';
        }

        if ($mode->requiresFullAddress()) {
            if ($shipper->name === null || trim($shipper->name) === '') {
                $errors[] = 'shipper.name is required for a booking (optional for a quotation).';
            }
            if ($shipper->address->street === null || trim($shipper->address->street) === '') {
                $errors[] = 'shipper.address.street is required for a booking (optional for a quotation).';
            }
            if ($shipper->address->city === null || trim($shipper->address->city) === '') {
                $errors[] = 'shipper.address.city is required for a booking.';
            }
            if (!$shipper->hasPickupDate()) {
                $errors[] = 'shipper.pickupOn (or pickupAtFrom + pickupAtUntil) is required for a booking.';
            }
        }
    }

    /** @param list<string> $errors */
    private function validateConsignee(Consignee $consignee, ValidationMode $mode, array &$errors): void
    {
        if ($consignee->address->postCode === '') {
            $errors[] = 'consignee.address.postCode is required.';
        }

        if ($mode->requiresFullAddress()) {
            if ($consignee->name === null || trim($consignee->name) === '') {
                $errors[] = 'consignee.name is required for a booking (optional for a quotation).';
            }
            if ($consignee->address->street === null || trim($consignee->address->street) === '') {
                $errors[] = 'consignee.address.street is required for a booking (optional for a quotation).';
            }
            if ($consignee->address->city === null || trim($consignee->address->city) === '') {
                $errors[] = 'consignee.address.city is required for a booking.';
            }
        }
    }

    /** @param list<string> $errors */
    private function validateDates(ShipmentRequest $request, array &$errors): void
    {
        $shipper   = $request->shipper;
        $consignee = $request->consignee;

        $pickupOn    = DateFormat::parse($shipper->pickupOn);
        $pickupFrom  = DateFormat::parse($shipper->pickupAtFrom);
        $pickupUntil = DateFormat::parse($shipper->pickupAtUntil);
        $deliveryOn  = DateFormat::parse($consignee->deliveryOn);

        if ($shipper->pickupOn !== null && $pickupOn === null) {
            $errors[] = 'shipper.pickupOn is not a valid date.';
        }
        if ($shipper->pickupAtFrom !== null && $pickupFrom === null) {
            $errors[] = 'shipper.pickupAtFrom is not a valid date-time.';
        }
        if ($shipper->pickupAtUntil !== null && $pickupUntil === null) {
            $errors[] = 'shipper.pickupAtUntil is not a valid date-time.';
        }
        if ($consignee->deliveryOn !== null && $deliveryOn === null) {
            $errors[] = 'consignee.deliveryOn is not a valid date.';
        }

        // A window needs both ends, and they have to point forwards.
        if ($pickupFrom !== null && $pickupUntil === null) {
            $errors[] = 'shipper.pickupAtUntil is required when pickupAtFrom is set.';
        }
        if ($pickupUntil !== null && $pickupFrom === null) {
            $errors[] = 'shipper.pickupAtFrom is required when pickupAtUntil is set.';
        }
        if ($pickupFrom !== null && $pickupUntil !== null && $pickupFrom > $pickupUntil) {
            $errors[] = 'shipper.pickupAtFrom must not be later than shipper.pickupAtUntil.';
        }

        // Cargoboard: "If value of pickupOn and pickupAtFrom and pickupAtUntil is set, date part
        // (a day) must be the same."
        foreach (['pickupAtFrom' => $pickupFrom, 'pickupAtUntil' => $pickupUntil] as $field => $windowEnd) {
            if ($pickupOn !== null && $windowEnd !== null && $pickupOn->format('Y-m-d') !== $windowEnd->format('Y-m-d')) {
                $errors[] = sprintf(
                    'shipper.%s must fall on the same day as shipper.pickupOn (%s).',
                    $field,
                    $pickupOn->format('Y-m-d'),
                );
            }
        }

        // Collections and deliveries run Monday to Friday.
        if ($pickupOn !== null && $this->isWeekend($pickupOn)) {
            $errors[] = 'shipper.pickupOn must be a weekday (Monday to Friday).';
        }
        if ($pickupOn === null && $pickupFrom !== null && $this->isWeekend($pickupFrom)) {
            $errors[] = 'shipper.pickupAtFrom must be a weekday (Monday to Friday).';
        }
        if ($deliveryOn !== null && $this->isWeekend($deliveryOn)) {
            $errors[] = 'consignee.deliveryOn must be a weekday (Monday to Friday).';
        }

        // Cargoboard: "The delivery date may only be specified if the product FIX is booked."
        if ($consignee->deliveryOn !== null && !$request->product->isFix()) {
            $errors[] = sprintf(
                'consignee.deliveryOn may only be set for a FIX product, not for %s.',
                $request->product->value,
            );
        }

        if ($deliveryOn !== null && $pickupOn !== null && $deliveryOn < $pickupOn) {
            $errors[] = 'consignee.deliveryOn must not be earlier than shipper.pickupOn.';
        }
    }

    /** @param list<string> $errors */
    private function validateLines(ShipmentRequest $request, array &$errors): void
    {
        if ($request->lines === []) {
            $errors[] = 'lines must contain at least one line.';
            return;
        }

        foreach ($request->lines as $index => $line) {
            $prefix = "lines[{$index}]";

            if (trim($line->content) === '') {
                $errors[] = "{$prefix}.content is required.";
            }
            if ($line->unitQuantity < 1) {
                $errors[] = "{$prefix}.unitQuantity must be at least 1.";
            }
            foreach (['unitLength' => $line->unitLength, 'unitWidth' => $line->unitWidth, 'unitHeight' => $line->unitHeight] as $field => $value) {
                if ($value <= 0) {
                    $errors[] = "{$prefix}.{$field} must be greater than 0 (dimensions are in centimetres).";
                }
            }
            if ($line->unitWeight <= 0) {
                $errors[] = "{$prefix}.unitWeight must be greater than 0 (weight is in kilograms, per unit).";
            }

            if ($line->unitPackageType->isLoadingMetres()) {
                $this->validateLoadingMetreLine($line, $prefix, $errors);
            }

            foreach ($line->dangerousGoods as $dgIndex => $good) {
                $dgPrefix = "{$prefix}.dangerousGoods[{$dgIndex}]";

                if (trim($good->unNo) === '') {
                    $errors[] = "{$dgPrefix}.unNo is required for a dangerous goods declaration.";
                }
                if (trim($good->substanceName) === '') {
                    $errors[] = "{$dgPrefix}.substanceName is required for a dangerous goods declaration.";
                }
            }
        }
    }

    /** @param list<string> $errors */
    private function validateLoadingMetreLine(Line $line, string $prefix, array &$errors): void
    {
        if ($line->unitWidth !== self::LDM_WIDTH_CM) {
            $errors[] = sprintf(
                '%s.unitWidth must be %d for a PARTIE (loading-metre) line; got %d.',
                $prefix,
                self::LDM_WIDTH_CM,
                $line->unitWidth,
            );
        }
        if (!in_array($line->unitHeight, self::LDM_HEIGHTS_CM, true)) {
            $errors[] = sprintf(
                '%s.unitHeight must be one of %s for a PARTIE (loading-metre) line (250/260 tractor-trailer, 300 Megaliner); got %d.',
                $prefix,
                implode(', ', self::LDM_HEIGHTS_CM),
                $line->unitHeight,
            );
        }
        if ($line->unitQuantity !== 1) {
            $errors[] = "{$prefix}.unitQuantity must be 1 for a PARTIE (loading-metre) line; the loading metres go into unitLength.";
        }
    }

    /** @param list<string> $errors */
    private function validateProductAndVehicle(ShipmentRequest $request, array &$errors): void
    {
        $vehicleLines = [];

        foreach ($request->lines as $index => $line) {
            if ($line->unitPackageType->isVehicle()) {
                $vehicleLines[$index] = $line;
            }
        }

        if ($vehicleLines === []) {
            return;
        }

        if (count($vehicleLines) !== count($request->lines)) {
            $errors[] = 'lines must not mix a DIRECT_* vehicle line with ordinary package lines; a vehicle booking is the whole shipment.';
        }
        if (count($vehicleLines) > 1) {
            $errors[] = 'lines must contain exactly one DIRECT_* vehicle line; book one vehicle per shipment.';
        }

        foreach ($vehicleLines as $index => $line) {
            $prefix    = "lines[{$index}]";
            $truckType = $line->unitPackageType->truckType();

            if ($line->unitQuantity !== 1) {
                $errors[] = "{$prefix}.unitQuantity must be 1 for a vehicle booking.";
            }

            if ($truckType === null) {
                continue;
            }

            $allowed = $truckType->allowedProducts();
            if (!in_array($request->product, $allowed, true)) {
                $errors[] = sprintf(
                    'product must be %s when booking %s, got %s.',
                    implode(' or ', array_map(static fn ($p) => $p->value, $allowed)),
                    $line->unitPackageType->value,
                    $request->product->value,
                );
            }

            $payload = $line->totalWeightKg();
            if ($payload > $truckType->maxPayloadKg()) {
                $errors[] = sprintf(
                    '%s.unitWeight of %.1f kg exceeds the %d kg payload of %s.',
                    $prefix,
                    $payload,
                    $truckType->maxPayloadKg(),
                    $line->unitPackageType->value,
                );
            }
        }
    }

    /** @param list<string> $errors */
    private function validateInsuranceAndValue(ShipmentRequest $request, array &$errors): void
    {
        // Cargoboard: "goodsValueAmount is necessary if you set wantsInsurance".
        if ($request->wantsInsurance === true && ($request->valueOfGoodsAmount === null || $request->valueOfGoodsAmount <= 0)) {
            $errors[] = 'valueOfGoodsAmount is required (and must be greater than 0) when wantsInsurance is true.';
        }

        if ($request->valueOfGoodsAmount !== null && $request->valueOfGoodsAmount < 0) {
            $errors[] = 'valueOfGoodsAmount must not be negative.';
        }

        if ($request->valueOfGoodsCurrency !== null && strtoupper($request->valueOfGoodsCurrency) !== 'EUR') {
            $errors[] = sprintf(
                'valueOfGoodsCurrency must be EUR; got "%s". Convert the amount and send valueOfGoodsAmountEur.',
                $request->valueOfGoodsCurrency,
            );
        }
    }

    /**
     * Parcel-mode rules. These only apply when the request goes out with the
     * `x-transport-type-parcel-is-active` header; the same payload is perfectly valid freight.
     *
     * @param list<string> $errors
     */
    private function validateParcel(ShipmentRequest $request, array &$errors): void
    {
        $from = $request->shipper->address->countryCode;
        $to   = $request->consignee->address->countryCode;

        // Cargoboard offers parcel on DE -> DE, DE -> EU and EU -> DE, but not EU -> EU.
        if ($from !== CountryCode::DE && $to !== CountryCode::DE) {
            $errors[] = sprintf(
                'parcel shipments must start or end in Germany; %s to %s is not offered (book it as freight).',
                $from->value,
                $to->value,
            );
        }

        if ($request->shipper->neutralData !== null || $request->consignee->neutralData !== null) {
            $errors[] = 'neutralData is not supported for parcel shipments.';
        }

        if ($request->hasDangerousGoods()) {
            $errors[] = 'dangerous goods (including LQ and EQ) are not available for parcel shipments.';
        }

        if ($request->wantsInsurance === true
            && $request->valueOfGoodsAmount !== null
            && $request->valueOfGoodsAmount > ParcelLimits::MAX_INSURED_VALUE_EUR
        ) {
            $errors[] = sprintf(
                'valueOfGoodsAmount of %.2f exceeds the EUR %s insurable maximum for parcel shipments.',
                $request->valueOfGoodsAmount,
                number_format(ParcelLimits::MAX_INSURED_VALUE_EUR, 0, '.', ' '),
            );
        }

        $totalParcels = 0;

        foreach ($request->lines as $index => $line) {
            $prefix = "lines[{$index}]";
            $totalParcels += $line->unitQuantity;

            if ($line->unitPackageType !== PackageType::Carton) {
                $errors[] = sprintf(
                    '%s.unitPackageType should be KT (carton/parcel) for a parcel shipment; got %s.',
                    $prefix,
                    $line->unitPackageType->value,
                );
            }

            if ($line->unitWeight > ParcelLimits::MAX_WEIGHT_KG) {
                $errors[] = sprintf(
                    '%s.unitWeight of %.1f kg exceeds the %.0f kg parcel limit.',
                    $prefix,
                    $line->unitWeight,
                    ParcelLimits::MAX_WEIGHT_KG,
                );
            }

            $volumetric = $line->unitVolumetricWeightKg();
            if ($volumetric > ParcelLimits::MAX_WEIGHT_KG) {
                $errors[] = sprintf(
                    '%s volumetric weight of %.1f kg ((%d x %d x %d) / %d) exceeds the %.0f kg parcel limit.',
                    $prefix,
                    $volumetric,
                    $line->unitLength,
                    $line->unitWidth,
                    $line->unitHeight,
                    ParcelLimits::VOLUMETRIC_DIVISOR,
                    ParcelLimits::MAX_WEIGHT_KG,
                );
            }

            [$longest, $second] = $line->sortedSides();

            if ($longest > ParcelLimits::MAX_LONGEST_SIDE_CM) {
                $errors[] = sprintf(
                    '%s longest side of %d cm exceeds the %d cm parcel limit.',
                    $prefix,
                    $longest,
                    ParcelLimits::MAX_LONGEST_SIDE_CM,
                );
            }
            if ($second > ParcelLimits::MAX_SECOND_SIDE_CM) {
                $errors[] = sprintf(
                    '%s second-longest side of %d cm exceeds the %d cm parcel limit.',
                    $prefix,
                    $second,
                    ParcelLimits::MAX_SECOND_SIDE_CM,
                );
            }

            $girth = $line->girthCm();
            if ($girth > ParcelLimits::MAX_GIRTH_CM) {
                $errors[] = sprintf(
                    '%s girth of %d cm (length + 2x width + 2x height) exceeds the %d cm parcel limit.',
                    $prefix,
                    $girth,
                    ParcelLimits::MAX_GIRTH_CM,
                );
            }
        }

        if ($totalParcels > ParcelLimits::MAX_PARCELS_PER_PICKUP_PER_DAY) {
            $errors[] = sprintf(
                'lines add up to %d parcels; Cargoboard collects at most %d per pickup location per day without a dedicated arrangement.',
                $totalParcels,
                ParcelLimits::MAX_PARCELS_PER_PICKUP_PER_DAY,
            );
        }
    }

    private function isWeekend(\DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), [6, 7], true);
    }
}
