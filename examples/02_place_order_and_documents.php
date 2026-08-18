<?php

/**
 * Example 02. Book directly, then download the paperwork.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - placeOrder(): pricing and booking in one call, when you do not need to
 *     show the customer a price first
 *   - the shipment labels (A4 for an office printer, A6 for a label printer)
 *   - the order confirmation PDF
 *   - cancelling an order, and what happens when it is too late to
 *
 * Run:
 *   CARGOBOARD_API_KEY=xxx php examples/02_place_order_and_documents.php
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
use VeryCodeCom\Cargoboard\Enum\LabelFormat;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardConflictException;
use VeryCodeCom\Cargoboard\Exception\CargoboardValidationException;

$client = CargoboardClient::sandbox(getenv('CARGOBOARD_API_KEY') ?: 'your-api-key');

// Two pallets, insured, with a fixed delivery day. Note that deliveryOn is only
// allowed on a FIX product; sending it on STANDARD is rejected locally.
$request = new ShipmentRequest(
    product: Product::Fix,
    shipper: new Shipper(
        address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
        name: 'Mustermann GmbH',
        pickupOn: (new DateTimeImmutable('+2 weekdays'))->format('Y-m-d'),
        wantsTailLiftTruck: true,
    ),
    consignee: new Consignee(
        address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
        name: 'Fabian Müller',
        isPrivateCustomer: true,           // B2C: changes both price and handling
        wantsContactBeforeDelivery: true,
        deliveryOn: (new DateTimeImmutable('+5 weekdays'))->format('Y-m-d'),
    ),
    lines: [
        new Line(
            content: 'Werkzeugmaschine',
            unitQuantity: 2,
            unitPackageType: PackageType::EuroPallet,
            unitLength: 120,
            unitWidth: 80,
            unitHeight: 100,
            unitWeight: 180.0,
            isStackable: true,
        ),
    ],
    customerOrderCode: 'ORDER-' . date('YmdHis'),
    wantsInsurance: true,
    valueOfGoodsAmount: 3000.0,            // required whenever wantsInsurance is true
    valueOfGoodsCurrency: 'EUR',
);

try {
    $order = $client->placeOrder($request);

    echo "Booked {$order->reference} (id {$order->id}) for {$order->price}\n";
    echo "  Total weight: {$request->totalWeightKg()} kg over {$request->totalUnits()} unit(s)\n\n";

    // -----------------------------------------------------------------------
    // Labels. A4 puts several on one sheet; A6 is one label per page, which is
    // what a thermal label printer expects. Both come back as raw PDF bytes.
    // -----------------------------------------------------------------------
    foreach ([LabelFormat::A4, LabelFormat::A6] as $format) {
        $pdf = $client->fetchLabels($order->id, $format);
        $path = __DIR__ . '/labels-' . strtolower($format->value) . '.pdf';
        file_put_contents($path, $pdf);
        printf("  Labels %s: %s (%d bytes)\n", $format->value, basename($path), strlen($pdf));
    }

    $confirmation = $client->fetchConfirmation($order->id);
    file_put_contents(__DIR__ . '/confirmation.pdf', $confirmation);
    printf("  Confirmation: confirmation.pdf (%d bytes)\n", strlen($confirmation));

    // -----------------------------------------------------------------------
    // Cancelling. Only possible while the shipment has not been collected;
    // afterwards Cargoboard answers 409 and the client raises a conflict.
    // -----------------------------------------------------------------------
    echo "\nCancelling the order again (this is a sandbox booking)...\n";
    $result = $client->cancelOrder($order->id);
    echo "  Status : " . ($result->status ?? 'n/a') . "\n";
    echo "  Message: " . ($result->message ?? 'n/a') . "\n";
} catch (CargoboardValidationException $e) {
    echo "Local validation failed:\n";
    foreach ($e->errors as $error) {
        echo "  - {$error}\n";
    }
} catch (CargoboardConflictException $e) {
    echo "Cannot cancel this order any more: {$e->getMessage()}\n";
} catch (CargoboardApiException $e) {
    echo "Cargoboard API error (HTTP {$e->statusCode}): {$e->getMessage()}\n";
}
