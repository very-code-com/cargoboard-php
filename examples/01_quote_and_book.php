<?php

/**
 * Example 01. Get a binding price, then book it.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - the quotation-then-booking workflow, which is what most integrations want
 *   - the same ShipmentRequest serving both calls (Cargoboard uses one body for both)
 *   - reading the price breakdown, transit time and delivery window
 *   - handling every exception type the client can throw
 *
 * Booking through the quotation id guarantees the price you were quoted; calling
 * placeOrder() directly would re-price the shipment at booking time.
 *
 * Run:
 *   CARGOBOARD_API_KEY=xxx php examples/01_quote_and_book.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\ContactPerson;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAuthException;
use VeryCodeCom\Cargoboard\Exception\CargoboardConflictException;
use VeryCodeCom\Cargoboard\Exception\CargoboardException;
use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Exception\CargoboardValidationException;
use VeryCodeCom\Cargoboard\Tracking\TrackingUrl;

// ---------------------------------------------------------------------------
// 1. Build the client. ::sandbox() targets api-sandbox.cargoboard.com, where no
//    truck is ever scheduled. Cargoboard issues separate keys per environment.
// ---------------------------------------------------------------------------
$client = CargoboardClient::sandbox(getenv('CARGOBOARD_API_KEY') ?: 'your-api-key');

// ---------------------------------------------------------------------------
// 2. Describe the shipment once. For a quotation only post code + country are
//    mandatory; the names and streets below are what make it bookable, so the
//    same object can be used for both calls.
// ---------------------------------------------------------------------------
$request = new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(
        address: new Address(
            postCode: '40239',
            countryCode: CountryCode::DE,
            city: 'Düsseldorf',
            street: 'Examplestreet 12a',
        ),
        name: 'Producer ABC GmbH & Co. KG',
        contactPerson: new ContactPerson('Mr. Skywalker', '+4917287502631', 'orders@example.com'),
        pickupOn: (new DateTimeImmutable('+2 weekdays'))->format('Y-m-d'),
        freeTextForPickup: 'Ramp 3, closed between 12:00 and 12:45',
    ),
    consignee: new Consignee(
        address: new Address(
            postCode: '41061',
            countryCode: CountryCode::DE,
            city: 'Mönchengladbach',
            street: 'Examplestreet 5',
        ),
        name: 'Consignee ABC AG',
        wantsPhoneCallFromDriverBeforeDelivery: true,
    ),
    // Dimensions in centimetres and weight in kilograms, both PER UNIT.
    lines: [
        new Line(
            content: 'Werkzeugmaschine',
            unitQuantity: 1,
            unitPackageType: PackageType::EuroPallet,
            unitLength: 120,
            unitWidth: 80,
            unitHeight: 120,
            unitWeight: 200.0,
            isStackable: false,
            wantsPalletExchange: true,
        ),
    ],
    customerOrderCode: 'ORDER-' . date('YmdHis'),
);

try {
    // -----------------------------------------------------------------------
    // 3. Price it. The result is binding and carries the id needed to book it.
    // -----------------------------------------------------------------------
    $quotation = $client->quote($request);

    echo "Quotation {$quotation->id}\n";
    echo "  Price       : {$quotation->price}";
    if ($quotation->price->grossAmount !== null) {
        echo sprintf(' (gross %.2f)', $quotation->price->grossAmount);
    }
    echo "\n";
    echo "  Transit     : {$quotation->runtime}\n";
    echo "  Delivery    : "
        . ($quotation->delivery->earliest?->format('Y-m-d') ?? '?')
        . ' - ' . ($quotation->delivery->latest?->format('Y-m-d') ?? '?') . "\n";
    echo "  CO2         : {$quotation->co2Emission}\n";
    echo "  Transport   : " . ($quotation->transportType?->value ?? 'n/a') . "\n";

    echo "  Breakdown:\n";
    foreach ($quotation->costItems as $item) {
        printf("    %-34s %10s\n", $item->description, (string) $item->price);
    }

    // -----------------------------------------------------------------------
    // 4. Book it at exactly that price. The full booking payload is still
    //    required: Cargoboard needs the names, streets and pickup date.
    // -----------------------------------------------------------------------
    $order = $client->bookQuotation($quotation->id, $request);

    echo "\nBooked!\n";
    echo "  Order id    : {$order->id}\n";
    echo "  Reference   : {$order->reference}   <- the shipment number to quote in support\n";
    echo "  Price       : {$order->price}\n";
    echo "  Tracking    : " . TrackingUrl::forOrder($order, $request->consignee->address->postCode) . "\n";
} catch (CargoboardValidationException $e) {
    // Thrown BEFORE any network call when the shipment breaks a documented rule.
    echo "Local validation failed:\n";
    foreach ($e->errors as $error) {
        echo "  - {$error}\n";
    }
} catch (CargoboardAuthException $e) {
    echo "Authentication failed (HTTP {$e->statusCode}): {$e->getMessage()}\n";
} catch (CargoboardUnprocessableEntityException $e) {
    echo "Cargoboard rejected the payload:\n";
    foreach ($e->errors as $error) {
        echo "  - {$error}\n";
    }
} catch (CargoboardConflictException $e) {
    echo "The request conflicts with the current state: {$e->getMessage()}\n";
} catch (CargoboardApiException $e) {
    echo "Cargoboard API error (HTTP {$e->statusCode}): {$e->getMessage()}\n";
} catch (CargoboardTransportException $e) {
    echo "Network problem talking to Cargoboard: {$e->getMessage()}\n";
} catch (CargoboardException $e) {
    echo "Unexpected Cargoboard error: {$e->getMessage()}\n";
}
