<?php

/**
 * Example 07. Part loads by loading metre, and whole vehicles.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - Line::loadingMetres(): a part load described by the floor space it takes
 *     up, instead of listing every pallet
 *   - Line::vehicle(): booking a dedicated van or truck
 *   - which product each vehicle must be booked with, and its payload ceiling
 *   - loading and unloading types, and how they relate to the tail lift service
 *
 * Run:
 *   CARGOBOARD_API_KEY=xxx php examples/07_loading_metres_and_vehicles.php
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
use VeryCodeCom\Cargoboard\Enum\LoadingType;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Enum\TruckType;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;

$client = CargoboardClient::sandbox(getenv('CARGOBOARD_API_KEY') ?: 'your-api-key');

$shipper = new Shipper(
    address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'),
    name: 'Producer ABC GmbH & Co. KG',
    pickupOn: (new DateTimeImmutable('+2 weekdays'))->format('Y-m-d'),
    loadingType: LoadingType::Ramp,
);

$consignee = new Consignee(
    address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'),
    name: 'Consignee ABC AG',
    unloadingType: LoadingType::LiftingPlatformOrTailLiftTruck,
    // The unloading TYPE describes the method; booking the tail lift itself is
    // a separate service and needs this flag as well.
    wantsTailLiftTruck: true,
);

// ---------------------------------------------------------------------------
// 1. Loading metres (Lademeter/LDM). One PARTIE line, the metres expressed
//    through the length in centimetres, the width pinned to the truck bed.
// ---------------------------------------------------------------------------
$ldm = new ShipmentRequest(
    product: Product::Standard,
    shipper: $shipper,
    consignee: $consignee,
    lines: [Line::loadingMetres(loadingMetres: 7.2, totalWeightKg: 5800.0, content: 'Mixed goods', heightCm: 250)],
);

$ldmLine = $ldm->lines[0];
echo "Loading-metre booking\n";
printf("  %.1f LDM -> unitLength %d cm, width %d cm, height %d cm, %.0f kg\n",
    (float) $ldmLine->loadingMetreValue(),
    $ldmLine->unitLength,
    $ldmLine->unitWidth,
    $ldmLine->unitHeight,
    $ldmLine->unitWeight,
);
echo "  Heights: 250/260 for a tractor-trailer, 300 for a Megaliner.\n";
echo "  Validation: " . ($client->validateLocally($ldm) === [] ? 'OK' : 'failed') . "\n\n";

// ---------------------------------------------------------------------------
// 2. Whole vehicles. Everything but the 40-tonne truck is a DIRECT transport;
//    the 40-tonne truck runs on the STANDARD/EXPRESS network.
// ---------------------------------------------------------------------------
echo "Vehicle types\n";
foreach (TruckType::cases() as $truck) {
    printf(
        "  %-24s up to %6d kg   book as %s\n",
        $truck->value,
        $truck->maxPayloadKg(),
        implode(' or ', array_map(static fn (Product $p): string => $p->value, $truck->allowedProducts())),
    );
}

$vehicle = new ShipmentRequest(
    product: Product::Direct,
    shipper: $shipper,
    consignee: $consignee,
    lines: [Line::vehicle(PackageType::DirectTruck12, totalWeightKg: 4800.0, content: 'Machinery')],
);

echo "\n  Truck 12t with 4800 kg as DIRECT: " . ($client->validateLocally($vehicle) === [] ? 'OK' : 'failed') . "\n";

// The two mistakes the validator exists to catch:
$wrongProduct = new ShipmentRequest(
    product: Product::Standard,
    shipper: $shipper,
    consignee: $consignee,
    lines: [Line::vehicle(PackageType::DirectTruck12, 4800.0)],
);
foreach ($client->validateLocally($wrongProduct) as $error) {
    echo "  - {$error}\n";
}

$overloaded = new ShipmentRequest(
    product: Product::Direct,
    shipper: $shipper,
    consignee: $consignee,
    lines: [Line::vehicle(PackageType::DirectCurtainVan, 1500.0)],
);
foreach ($client->validateLocally($overloaded) as $error) {
    echo "  - {$error}\n";
}

// ---------------------------------------------------------------------------
// 3. Price both.
// ---------------------------------------------------------------------------
try {
    foreach (['loading metres' => $ldm, 'truck 12t' => $vehicle] as $label => $request) {
        $quotation = $client->quote($request);
        printf("\n%-15s %s  (%s, %s)\n",
            $label,
            (string) $quotation->price,
            (string) $quotation->runtime,
            $quotation->transportType?->value ?? 'n/a',
        );
    }
} catch (CargoboardApiException $e) {
    echo "\nCargoboard API error (HTTP {$e->statusCode}): {$e->getMessage()}\n";
}
