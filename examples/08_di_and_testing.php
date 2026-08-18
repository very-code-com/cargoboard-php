<?php

/**
 * Example 08. Dependency injection and testing. RUNS OFFLINE.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - swapping the cURL transport for a scripted fake, so an integration built
 *     on this client can be tested without a network or an API key
 *   - injecting a PSR-3 logger and seeing what the client logs
 *   - the three ways to build a config
 *
 * This is the exact pattern the library's own unit tests use. No API key and no
 * network are needed to run it.
 *
 * Run:
 *   php examples/08_di_and_testing.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Log\AbstractLogger;
use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\CargoboardConfig;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Transport\TransportInterface;
use VeryCodeCom\Cargoboard\Transport\TransportRequest;
use VeryCodeCom\Cargoboard\Transport\TransportResponse;

// ---------------------------------------------------------------------------
// 1. A scripted transport. Implement one method and the whole client is
//    testable: it never touches cURL directly.
// ---------------------------------------------------------------------------
final class ScriptedTransport implements TransportInterface
{
    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @param list<TransportResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        // Never log the real request: it carries the API key in a header.
        $this->requests[] = $request->redacted();

        return array_shift($this->responses)
            ?? new TransportResponse(500, '{"message":"no scripted response left"}');
    }
}

// ---------------------------------------------------------------------------
// 2. A PSR-3 logger that just prints, so the client's logging is visible.
// ---------------------------------------------------------------------------
final class EchoLogger extends AbstractLogger
{
    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        printf("  [%-7s] %s%s\n", $level, $message, $context === [] ? '' : ' ' . json_encode($context));
    }
}

$orderResponse = <<<'JSON'
{
  "data": {
    "id": "cm3328igs04jbqt0dluurnxyl",
    "reference": "10374504",
    "product": "STANDARD",
    "price": { "amount": 94.93, "currency": "EUR" },
    "priceStandard": { "amount": 94.93, "currency": "EUR" },
    "runtime": { "daysMin": 1, "daysMax": 2 },
    "delivery": { "earliest": "2026-08-21T07:00:00.000Z", "latest": "2026-08-24T15:00:00.000Z" },
    "co2Emission": { "amount": 0.7, "value": 35.58, "unit": "KG" },
    "costItems": [
      { "description": "Frachtkosten", "type": "SHIPMENT", "subtype": null, "price": { "amount": 94.23, "currency": "EUR" } }
    ]
  },
  "links": [
    { "rel": "orderTrack", "method": "GET", "description": "Track an order.", "href": "https://api-sandbox.cargoboard.com/v1/orders/10374504/tracking" }
  ]
}
JSON;

$transport = new ScriptedTransport([
    new TransportResponse(201, $orderResponse, ['content-type' => 'application/json']),
]);

$client = new CargoboardClient(
    config: CargoboardConfig::sandbox('test-key'),
    transport: $transport,
    logger: new EchoLogger(),
);

$request = new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(
        address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
        name: 'Mustermann GmbH',
        pickupOn: (new DateTimeImmutable('next monday'))->format('Y-m-d'),
    ),
    consignee: new Consignee(
        address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
        name: 'Fabian Müller',
    ),
    lines: [new Line('Werkzeugmaschine', 1, PackageType::EuroPallet, 120, 80, 120, 200.0)],
);

echo "Placing an order against the scripted transport:\n";
$order = $client->placeOrder($request);

echo "\nParsed result\n";
echo "  Reference: {$order->reference}\n";
echo "  Price    : {$order->price}\n";
echo "  Tracking : " . ($order->trackingApiUrl() ?? '-') . "\n";

echo "\nWhat went over the wire (credentials redacted)\n";
foreach ($transport->requests as $sent) {
    echo "  {$sent->method} {$sent->url}\n";
    echo "    X-API-KEY: {$sent->headers['X-API-KEY']}\n";
}

// ---------------------------------------------------------------------------
// 3. Error paths are just as easy to script, which is how you test your own
//    retry and alerting logic without provoking real failures.
// ---------------------------------------------------------------------------
$failing = new ScriptedTransport([
    new TransportResponse(
        422,
        '{"statusCode":422,"message":["shipper.address.countryCode must be one of the following values: AL, AT, BE"],"error":"Unprocessable Entity"}',
        ['content-type' => 'application/json'],
    ),
]);

echo "\nScripted failure\n";

try {
    (new CargoboardClient(CargoboardConfig::sandbox('test-key'), $failing))->placeOrder($request);
} catch (CargoboardUnprocessableEntityException $e) {
    echo "  Caught: {$e->getMessage()}\n";
    echo "  Fields: " . implode(', ', $e->getFieldNames()) . "\n";
}

// ---------------------------------------------------------------------------
// 4. The three ways to build a config.
// ---------------------------------------------------------------------------
echo "\nConfiguration\n";
echo '  ::sandbox()    -> ' . CargoboardConfig::sandbox('k')->getBaseUrl() . "\n";
echo '  ::production() -> ' . CargoboardConfig::production('k')->getBaseUrl() . "\n";
echo '  ::fromArray()  -> ' . CargoboardConfig::fromArray(['api_key' => 'k', 'env' => 'sandbox', 'timeout' => 45])->getEnvironment() . "\n";
echo "  ::fromEnv()    -> reads CARGOBOARD_API_KEY, CARGOBOARD_ENV, CARGOBOARD_TIMEOUT, ...\n";
