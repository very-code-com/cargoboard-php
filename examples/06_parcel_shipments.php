<?php

/**
 * Example 06. Parcel shipments.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - withParcelMode(): the header that turns a freight request into a parcel one
 *   - the parcel limits, and how the client catches breaches before sending
 *   - volumetric weight, which catches out big light boxes
 *   - the parcel-only services, and the ones that are not available
 *
 * Without the parcel header the very same payload is priced and booked as an
 * ordinary freight shipment, which is why this is a distinct client instance
 * rather than a flag on the request.
 *
 * Run:
 *   CARGOBOARD_API_KEY=xxx php examples/06_parcel_shipments.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Validation\ParcelLimits;

$freight = CargoboardClient::sandbox(getenv('CARGOBOARD_API_KEY') ?: 'your-api-key');
$parcels = $freight->withParcelMode();   // adds x-transport-type-parcel-is-active: true

echo "Parcel limits\n";
printf("  Weight (physical and volumetric): %.0f kg\n", ParcelLimits::MAX_WEIGHT_KG);
printf("  Longest side                    : %d cm\n", ParcelLimits::MAX_LONGEST_SIDE_CM);
printf("  Second-longest side             : %d cm\n", ParcelLimits::MAX_SECOND_SIDE_CM);
printf("  Girth (L + 2W + 2H)             : %d cm\n", ParcelLimits::MAX_GIRTH_CM);
printf("  Parcels per pickup per day      : %d\n", ParcelLimits::MAX_PARCELS_PER_PICKUP_PER_DAY);
printf("  Insurable goods value           : EUR %s\n\n", number_format(ParcelLimits::MAX_INSURED_VALUE_EUR, 0, '.', ' '));

$request = new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(
        address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'),
        name: 'Producer ABC GmbH & Co. KG',
        pickupOn: (new DateTimeImmutable('+2 weekdays'))->format('Y-m-d'),
    ),
    consignee: new Consignee(
        address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'),
        name: 'Consignee ABC AG',
    ),
    lines: [
        // Two identical parcels; use unitQuantity rather than repeating the line.
        new Line(
            content: 'Spare parts',
            unitQuantity: 2,
            unitPackageType: PackageType::Carton,   // KT is the parcel package type
            unitLength: 40,
            unitWidth: 30,
            unitHeight: 20,
            unitWeight: 5.0,
        ),
    ],
    customerOrderCode: 'PARCEL-' . date('YmdHis'),
    wantsInsurance: true,
    valueOfGoodsAmount: 1500.0,
    valueOfGoodsCurrency: 'EUR',
    wantsSignatureRequiredParcel: true,   // parcel-only add-on
    wantsDirectDeliveryParcel: true,      // parcel-only add-on
);

// ---------------------------------------------------------------------------
// Volumetric weight: the higher of physical and volumetric counts, and both
// must stay at or below 32 kg. A big light box fails on the volumetric side.
// ---------------------------------------------------------------------------
$line = $request->lines[0];
printf("This parcel: %.1f kg physical, %.1f kg volumetric (%d x %d x %d / %d)\n\n",
    $line->unitWeight,
    $line->unitVolumetricWeightKg(),
    $line->unitLength,
    $line->unitWidth,
    $line->unitHeight,
    ParcelLimits::VOLUMETRIC_DIVISOR,
);

$oversized = new Line('Foam blocks', 1, PackageType::Carton, 100, 76, 30, 2.0);
printf("A 100x76x30 box weighing only 2 kg is volumetrically %.1f kg, so it is NOT a parcel.\n\n",
    $oversized->unitVolumetricWeightKg());

// ---------------------------------------------------------------------------
// The client applies the parcel rules only in parcel mode: the same shipment
// is fine as freight and refused as a parcel, or the other way round.
// ---------------------------------------------------------------------------
echo "Validation of the oversized box:\n";
foreach ($parcels->validateLocally($request->withProduct(Product::Standard)) as $error) {
    echo "  - {$error}\n";
}

$tooBig = new ShipmentRequest(
    product: Product::Standard,
    shipper: $request->shipper,
    consignee: $request->consignee,
    lines: [$oversized],
);

foreach ($parcels->validateLocally($tooBig) as $error) {
    echo "  - {$error}\n";
}
echo "  (as freight: " . ($freight->validateLocally($tooBig) === [] ? 'perfectly valid' : 'invalid') . ")\n\n";

try {
    $quotation = $parcels->quote($request);
    echo "Parcel quotation {$quotation->id}: {$quotation->price} ({$quotation->runtime})\n";

    $order = $parcels->placeOrder($request);
    echo "Booked 2 parcels as {$order->reference}\n";
    echo "\nNote: parcel tracking only starts once the parcel is scanned at the pickup\n";
    echo "depot; before that there is a pickup status only.\n";
} catch (CargoboardApiException $e) {
    echo "Cargoboard API error (HTTP {$e->statusCode}): {$e->getMessage()}\n";
}
