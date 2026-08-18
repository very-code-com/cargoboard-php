<?php

/**
 * Example 09. Every local rule, and the whole exception hierarchy. RUNS OFFLINE.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - each documented business rule the client checks before sending anything
 *   - the difference between quotation rules and booking rules
 *   - which exception to catch for which failure, and what each one carries
 *
 * No API key and no network are needed: every rule below is enforced locally,
 * and the exceptions are constructed rather than provoked.
 *
 * Run:
 *   php examples/09_validation_and_errors.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\CargoboardConfig;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\DangerousGood;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\NeutralData;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAuthException;
use VeryCodeCom\Cargoboard\Exception\CargoboardConflictException;
use VeryCodeCom\Cargoboard\Exception\CargoboardException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;
use VeryCodeCom\Cargoboard\Exception\CargoboardRateLimitException;
use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Exception\CargoboardServerException;
use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Exception\CargoboardValidationException;
use VeryCodeCom\Cargoboard\Validation\ValidationMode;

$client  = new CargoboardClient(CargoboardConfig::sandbox('no-network-needed'));
$parcels = $client->withParcelMode();

$monday   = (new DateTimeImmutable('next monday'))->format('Y-m-d');
$saturday = (new DateTimeImmutable('next saturday'))->format('Y-m-d');
// Four days after the pickup Monday, i.e. the Friday of the same week.
$friday   = (new DateTimeImmutable('next monday'))->modify('+4 days')->format('Y-m-d');

$fullShipper = new Shipper(
    address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
    name: 'Mustermann GmbH',
    pickupOn: $monday,
);
$fullConsignee = new Consignee(
    address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
    name: 'Fabian Müller',
);
$pallet = new Line('Werkzeugmaschine', 1, PackageType::EuroPallet, 120, 80, 120, 200.0);

/** @param list<string> $errors */
$report = static function (string $title, array $errors): void {
    echo "\n{$title}\n";
    if ($errors === []) {
        echo "  (valid)\n";
        return;
    }
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
};

echo "=== Local validation rules ===\n";

// ---------------------------------------------------------------------------
// A quotation needs far less than a booking, which is the single most useful
// thing to know about this API's request body.
// ---------------------------------------------------------------------------
$minimal = new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(address: new Address('10115', CountryCode::DE, 'Berlin'), pickupOn: $monday),
    consignee: new Consignee(address: new Address('33100', CountryCode::DE, 'Paderborn')),
    lines: [$pallet],
);

$report('Minimal request, as a QUOTATION', $client->validateLocally($minimal, ValidationMode::Quotation));
$report('The same request, as a BOOKING', $client->validateLocally($minimal, ValidationMode::Order));

// ---------------------------------------------------------------------------
// Pickup and delivery days.
// ---------------------------------------------------------------------------
$report('Weekend pickup', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(address: $fullShipper->address, name: 'Mustermann GmbH', pickupOn: $saturday),
    consignee: $fullConsignee,
    lines: [$pallet],
)));

$report('pickupOn and the pickup window disagreeing on the day', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(
        address: $fullShipper->address,
        name: 'Mustermann GmbH',
        pickupOn: $monday,
        pickupAtFrom: $friday . 'T08:00:00Z',
        pickupAtUntil: $friday . 'T14:00:00Z',
    ),
    consignee: $fullConsignee,
    lines: [$pallet],
)));

// ---------------------------------------------------------------------------
// deliveryOn is a FIX-only field.
// ---------------------------------------------------------------------------
$report('deliveryOn on a STANDARD product', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: $fullShipper,
    consignee: new Consignee(address: $fullConsignee->address, name: 'Fabian Müller', deliveryOn: $friday),
    lines: [$pallet],
)));

$report('deliveryOn on a FIX product', $client->validateLocally(new ShipmentRequest(
    product: Product::Fix,
    shipper: $fullShipper,
    consignee: new Consignee(address: $fullConsignee->address, name: 'Fabian Müller', deliveryOn: $friday),
    lines: [$pallet],
)));

// ---------------------------------------------------------------------------
// Vehicles: product and payload.
// ---------------------------------------------------------------------------
$report('A 12 t truck booked as STANDARD', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: $fullShipper,
    consignee: $fullConsignee,
    lines: [Line::vehicle(PackageType::DirectTruck12, 4000.0)],
)));

$report('A curtain-side van loaded with 1500 kg', $client->validateLocally(new ShipmentRequest(
    product: Product::Direct,
    shipper: $fullShipper,
    consignee: $fullConsignee,
    lines: [Line::vehicle(PackageType::DirectCurtainVan, 1500.0)],
)));

// ---------------------------------------------------------------------------
// Loading metres: fixed width and a standard height.
// ---------------------------------------------------------------------------
$report('A PARTIE line with the wrong width and height', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: $fullShipper,
    consignee: $fullConsignee,
    lines: [new Line('goods', 1, PackageType::LoadingMetres, 720, 200, 275, 5800.0)],
)));

// ---------------------------------------------------------------------------
// Insurance and goods value.
// ---------------------------------------------------------------------------
$report('wantsInsurance without a goods value', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: $fullShipper,
    consignee: $fullConsignee,
    lines: [$pallet],
    wantsInsurance: true,
)));

$report('A goods value in the wrong currency', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: $fullShipper,
    consignee: $fullConsignee,
    lines: [$pallet],
    valueOfGoodsAmount: 1000.0,
    valueOfGoodsCurrency: 'PLN',
)));

// ---------------------------------------------------------------------------
// Dangerous goods.
// ---------------------------------------------------------------------------
$report('A dangerous goods entry without a UN number', $client->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: $fullShipper,
    consignee: $fullConsignee,
    lines: [new Line('Batteries', 1, PackageType::Carton, 33, 31, 45, 11.0, dangerousGoods: [new DangerousGood('', '')])],
)));

// ---------------------------------------------------------------------------
// Parcel mode. Note that the pallet shipment below is perfectly valid freight.
// ---------------------------------------------------------------------------
$report('A pallet shipment sent in parcel mode', $parcels->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: $fullShipper,
    consignee: $fullConsignee,
    lines: [$pallet],
)));

$report('A parcel shipment from Poland to Austria', $parcels->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(address: new Address('00-001', CountryCode::PL, 'Warszawa', 'ul. Testowa 1'), name: 'Nadawca', pickupOn: $monday),
    consignee: new Consignee(address: new Address('1000', CountryCode::AT, 'Wien', 'Teststrasse 1'), name: 'Empfänger'),
    lines: [new Line('Spare parts', 1, PackageType::Carton, 40, 30, 20, 5.0)],
)));

$report('A parcel with a neutral address and 25 units', $parcels->validateLocally(new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(
        address: $fullShipper->address,
        name: 'Mustermann GmbH',
        neutralData: new NeutralData('Selling Company ABC GmbH', 'Friedrichstraße 1', '10115', 'Berlin', CountryCode::DE),
        pickupOn: $monday,
    ),
    consignee: $fullConsignee,
    lines: [new Line('Spare parts', 25, PackageType::Carton, 40, 30, 20, 5.0)],
)));

// ---------------------------------------------------------------------------
// The exception hierarchy. Catch the narrowest type that describes what you
// want to do about the failure.
// ---------------------------------------------------------------------------
echo "\n=== Exception hierarchy ===\n\n";
echo "  CargoboardException                      base type, carries the raw response in debug mode\n";
echo "  |- CargoboardValidationException         local rules failed, nothing was sent\n";
echo "  |- CargoboardTransportException          network, DNS, TLS, timeout\n";
echo "  |- CargoboardResponseParseException      unreadable or unexpected response\n";
echo "  '- CargoboardApiException                Cargoboard answered with an error status\n";
echo "     |- CargoboardAuthException            401 / 403 - key missing or rejected\n";
echo "     |- CargoboardNotFoundException        404 - unknown order, quotation, invoice, UN number\n";
echo "     |- CargoboardConflictException        409 - state does not allow it (e.g. too late to cancel)\n";
echo "     |- CargoboardUnprocessableEntityException  422 - per-field messages\n";
echo "     |- CargoboardRateLimitException       429 - back off, retryAfterSeconds()\n";
echo "     '- CargoboardServerException          5xx  - isRetryable() for 502/503/504\n";

$samples = [
    new CargoboardValidationException(['shipper.name is required for a booking (optional for a quotation).']),
    new CargoboardAuthException('key rejected', 403),
    new CargoboardNotFoundException('no such order', 404),
    new CargoboardConflictException('already collected', 409),
    new CargoboardUnprocessableEntityException('bad payload', 422, ['lines.0.unitWeight must be a positive number']),
    (new CargoboardRateLimitException('slow down', 429))->withRetryAfter(120),
    new CargoboardServerException('bad gateway', 502),
    new CargoboardTransportException('cURL error #28: operation timed out'),
    new CargoboardResponseParseException('malformed JSON'),
];

echo "\nHow each one behaves:\n";
foreach ($samples as $exception) {
    $note = match (true) {
        $exception instanceof CargoboardValidationException => count($exception->errors) . ' local error(s)',
        $exception instanceof CargoboardRateLimitException  => 'retry after ' . $exception->retryAfterSeconds() . 's',
        $exception instanceof CargoboardServerException     => 'retryable: ' . var_export($exception->isRetryable(), true),
        $exception instanceof CargoboardAuthException       => 'missing credentials: ' . var_export($exception->isMissingCredentials(), true),
        $exception instanceof CargoboardApiException        => 'HTTP ' . $exception->statusCode,
        default                                             => 'not an API error',
    };

    printf("  %-45s %s\n", (new ReflectionClass($exception))->getShortName(), $note);
}

// Catch order matters: the subtypes have to come before CargoboardApiException.
try {
    throw new CargoboardUnprocessableEntityException('bad payload', 422, ['lines.0.unitWeight must be a positive number']);
} catch (CargoboardUnprocessableEntityException $e) {
    echo "\nCaught a 422; the fields Cargoboard named: " . implode(', ', $e->getFieldNames()) . "\n";
} catch (CargoboardApiException) {
    echo "\n(unreachable: the narrower catch above wins)\n";
} catch (CargoboardException) {
    echo "\n(unreachable)\n";
}
