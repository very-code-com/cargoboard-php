# cargoboard-php

**PHP client library for the Cargoboard API.**

An open-source PHP client for [Cargoboard](https://cargoboard.com), the digital freight forwarder:
get binding prices, book shipments, download labels and confirmations, track shipments in real
time, pull invoices, and look up ADR dangerous-goods data. Covers groupage, LTL, FTL, part loads
by loading metre, whole vehicles and parcels across 32 European countries.

[![Latest Version](https://img.shields.io/packagist/v/very-code-com/cargoboard-php.svg)](https://packagist.org/packages/very-code-com/cargoboard-php)
[![Total Downloads](https://img.shields.io/packagist/dt/very-code-com/cargoboard-php.svg)](https://packagist.org/packages/very-code-com/cargoboard-php)
[![CI](https://github.com/very-code-com/cargoboard-php/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/very-code-com/cargoboard-php/actions/workflows/ci.yml)
[![Integration](https://github.com/very-code-com/cargoboard-php/actions/workflows/integration.yml/badge.svg?branch=master)](https://github.com/very-code-com/cargoboard-php/actions/workflows/integration.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](https://phpstan.org)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue)](https://www.php.net)
[![License](https://img.shields.io/packagist/l/very-code-com/cargoboard-php.svg)](LICENSE)

---

## Requirements

- PHP 8.2+
- `ext-curl`
- `ext-json`

---

## Installation

```bash
composer require very-code-com/cargoboard-php
```

---

## Quick Start

```php
use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\Dto\{Address, Consignee, Line, ShipmentRequest, Shipper};
use VeryCodeCom\Cargoboard\Enum\{CountryCode, PackageType, Product};

$client = CargoboardClient::sandbox('your-api-key');

$request = new ShipmentRequest(
    product: Product::Standard,
    shipper: new Shipper(
        address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'),
        name: 'Producer ABC GmbH & Co. KG',
        pickupOn: '2026-08-20',
    ),
    consignee: new Consignee(
        address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'),
        name: 'Consignee ABC AG',
    ),
    // Dimensions in centimetres, weight in kilograms, both PER UNIT.
    lines: [new Line('Werkzeugmaschine', 1, PackageType::EuroPallet, 120, 80, 120, 200.0)],
);

// 1. Get a binding price.
$quotation = $client->quote($request);
echo $quotation->price;            // 90.85 EUR
echo $quotation->runtime;          // 1-2 days

// 2. Book that exact price.
$order = $client->bookQuotation($quotation->id, $request);
echo $order->reference;            // 10374504  <- the shipment number

// 3. Get the labels.
file_put_contents('labels.pdf', $client->fetchLabels($order->id));
```

See [`examples/`](examples/) for complete, runnable scripts.

---

## What it covers

Every endpoint of Cargoboard's public API:

| Method | Endpoint |
|--------|----------|
| `quote()` | `POST /v1/quotations` |
| `listQuotations()` / `fetchQuotation()` | `GET /v1/quotations`, `GET /v1/quotations/{id}` |
| `bookQuotation()` | `POST /v1/quotations/{id}/book` |
| `placeOrder()` | `POST /v1/orders` |
| `listOrders()` / `fetchOrder()` | `GET /v1/orders`, `GET /v1/orders/{id}` |
| `cancelOrder()` | `POST /v1/orders/{id}/cancel` |
| `fetchLabels()` / `fetchConfirmation()` | `GET /v1/orders/{id}/print-shipment-labels`, `.../print-confirmation` |
| `fetchTracking()` | `GET /v1/orders/{id}/tracking` |
| `listInvoices()` / `fetchInvoicePdf()` | `GET /v1/invoices`, `GET /v1/invoices/{id}/pdf` |
| `fetchAdrData()` | `GET /v1/dangerous-goods/un-numbers/{unNumber}` |

Plus a parser for the Track & Trace **webhook** payload and builders for the customer-facing
**tracking links**.

---

## Configuration

```php
// Named constructors
$client = CargoboardClient::sandbox($apiKey);
$client = CargoboardClient::production($apiKey);

// From environment variables (recommended)
$client = new CargoboardClient(CargoboardConfig::fromEnv());

// From array (framework config)
$config = CargoboardConfig::fromArray([
    'api_key' => '...', 'env' => 'sandbox', 'timeout' => 30,
]);
```

| Env variable                 | Required | Default      | Description                                        |
|------------------------------|----------|--------------|----------------------------------------------------|
| `CARGOBOARD_API_KEY`         | yes      | -            | The key sent as the `X-API-KEY` header             |
| `CARGOBOARD_ENV`             | no       | `production` | `sandbox` or `production`                          |
| `CARGOBOARD_TIMEOUT`         | no       | `30`         | Request timeout (seconds)                          |
| `CARGOBOARD_CONNECT_TIMEOUT` | no       | `10`         | Connection timeout (seconds)                       |
| `CARGOBOARD_PARCEL_MODE`     | no       | `0`          | Send every request in parcel mode                  |
| `CARGOBOARD_DEBUG`           | no       | `0`          | `1`/`true` to enable verbose debug output          |

**Sandbox vs. production:**

| Environment | Base URL |
|-------------|----------|
| Sandbox (test system) | `https://api-sandbox.cargoboard.com` |
| Production  | `https://api.cargoboard.com` |

On the sandbox nothing is executed and no truck is scheduled; you still receive an order
confirmation by e-mail so you can check the data. On production every booking is a real,
billable transport.

### Getting an API key

Cargoboard issues **separate keys per environment**, and a key for one is rejected by the other
with HTTP 403 - the same response an invalid key gets.

- **Already have an account with the API enabled?** Your key is on the
  [integration settings page](https://my.cargoboard.com/account/integration).
- **No key yet?** Ask your Cargoboard contact, or e-mail <api@cargoboard.com>. Say which
  environment you need; ask for a sandbox key first.

If a key that "should work" returns `403 Forbidden resource`, check the cheapest cause first:
**a single mistyped character in the key produces exactly the same response as no key at all.**
The API returns an identical 403 for a corrupted signature, a missing header, a wrong-environment
key, an unactivated account, and an endpoint the key is not entitled to (`GET /v1/invoices` is a
separate entitlement). Nothing in the status, body or headers tells them apart, so re-copy the
key character for character before investigating anything else.

The host itself is easy to rule out - `GET /` is an unauthenticated health check that returns
`{"status":"ok",...}`.

### Debug mode

Set the `debug` flag (constructor arg, `CARGOBOARD_DEBUG=1`, or `'debug' => true` in
`fromArray`) to make the client attach the **raw Cargoboard response** to every thrown exception
and log a full **debug report** (message + raw JSON + stack trace) at `error` level through the
injected PSR-3 logger:

```php
$client = new CargoboardClient(
    CargoboardConfig::fromArray(['api_key' => $key, 'env' => 'sandbox', 'debug' => true]),
    logger: $myPsrLogger,
);

try {
    $client->placeOrder($request);
} catch (\VeryCodeCom\Cargoboard\Exception\CargoboardException $e) {
    echo $e->getRawResponse();   // exact JSON Cargoboard returned (or null)
    echo $e->getDebugReport();   // class + message + raw response + stack trace
}
```

Leave `debug` off in production to keep exceptions and logs concise.

---

## Three things worth knowing up front

**1. A quotation needs much less than a booking.** The request body is identical for both, but a
quotation only requires a post code and a country on each side, while a booking additionally
requires names, streets, cities and a pickup date. This library validates the same
`ShipmentRequest` under whichever set of rules applies, so a missing booking field fails locally
with a field-level message instead of as an HTTP 422.

**2. Parcels are a header, not a payload.** `withParcelMode()` adds
`x-transport-type-parcel-is-active: true`; without it the very same request is priced and booked
as freight. Parcel mode also switches on the parcel limits (32 kg physical and volumetric,
100/76 cm sides, 300 cm girth, 20 per pickup per day, no dangerous goods, no neutral address).

```php
$parcels = $client->withParcelMode();
$parcels->placeOrder($request);
```

**3. A tracking event with no message is not an empty event.** Roughly a third of the events on
the status feed have `message: null` and carry the refined collection and delivery windows
instead — the most useful thing on the feed. `label` is not part of this endpoint's schema at
all, so the obvious `label ?? message ?? code` prints a bare `540` for a third of the history.
Use `describe()`, and key stored rows on the event `id`: `(code, originatedAt)` is not unique.

```php
foreach ($client->fetchTracking($reference)->timeline() as $event) {
    printf("%-5s %s\n", $event->code, $event->describe());
    // 540   Estimates updated: collection 18.08.2026 07:00-15:00, delivery 19.08.2026 06:00 - 21.08.2026 14:00
}
```

---

## Local validation

Every quotation and booking is checked against Cargoboard's documented rules before a request
goes out, and the same check is available on demand:

```php
$errors = $client->validateLocally($request);   // list<string>, empty means valid
```

Covered: mandatory address fields per mode, Monday-to-Friday pickups and deliveries, the
`pickupOn`/`pickupAtFrom`/`pickupAtUntil` consistency rule, `deliveryOn` being FIX-only, per-line
content and dimensions, loading-metre geometry, vehicle payload ceilings and their required
products, insurance needing a goods value, EUR-only goods values, dangerous-goods declarations,
and the full parcel rule set. See [docs/API.md](docs/API.md#local-validation-rules).

Some rules Cargoboard enforces at the depot rather than with an HTTP status — a private consignee
booked without either `wantsContactBeforeDelivery` or `wantsDeliveryWithoutConsigneePresence` is
accepted by the API and then causes trouble on delivery. Those are warnings, never exceptions:
they are logged on every quote and booking, and `$client->warningsFor($request)` returns them.

---

## Documentation

| Document | Contents |
|----------|----------|
| [docs/API.md](docs/API.md) | Every client method, the DTOs they take and return, the local validation rules, and the exception hierarchy |
| [docs/CARGOBOARD-NOTES.md](docs/CARGOBOARD-NOTES.md) | Cargoboard's own quirks: the two authentication failure modes, the parcel header, the ADR endpoint's two documented shapes, schema drift, list-endpoint details |
| [examples/](examples/) | Ten runnable scripts covering quoting, booking, documents, tracking, dangerous goods, parcels, vehicles, DI, validation and webhooks |

---

## Dependency Injection & Testing

The client accepts a custom `TransportInterface` and PSR-3 logger:

```php
new CargoboardClient(
    config:    CargoboardConfig,
    transport: TransportInterface       = new CurlTransport(),
    logger:    ?Psr\Log\LoggerInterface = null,
)
```

Implement `TransportInterface::send(TransportRequest): TransportResponse` to swap in a PSR-18 HTTP
client adapter, or a scripted fake for tests; see
[`examples/08_di_and_testing.php`](examples/08_di_and_testing.php) and
[`tests/Unit/CargoboardClientTest.php`](tests/Unit/CargoboardClientTest.php) for the pattern used
by this library's own test suite (no real network calls).

`TransportRequest::redacted()` masks the API key, so a request can safely be logged.

---

## Running Tests

```bash
composer install

# Unit tests (no network required)
vendor/bin/phpunit --testsuite unit

# Integration tests against the real Cargoboard sandbox
CARGOBOARD_SANDBOX=1 CARGOBOARD_API_KEY=xxx vendor/bin/phpunit --testsuite integration

# Static analysis (PHPStan level 8)
vendor/bin/phpstan analyse --memory-limit=512M
```

The integration suite exercises quoting, booking, labels, confirmations, tracking, cancellation,
listing, invoices and the ADR lookup against `api-sandbox.cargoboard.com`. It places a real
sandbox order (harmless: nothing is executed) and cancels it again at the end; add
`CARGOBOARD_ALLOW_ORDERS=0` to skip those tests and keep the run read-only. **Never point it at a
production key.**

### Continuous integration

| Workflow | Job | What it does |
|----------|-----|--------------|
| [`ci.yml`](.github/workflows/ci.yml) | `test` | Unit tests on PHP 8.2/8.3/8.4, plus a `--prefer-lowest` leg on 8.2 that verifies the declared dependency floor (`psr/log` 2.0.0) actually works; lints every example and runs the three offline ones |
| | `analyse` | PHPStan level 8, and `composer validate --strict --check-lock` |
| | `security` | `composer audit --locked` against the advisory database |
| | `coverage` | Unit line coverage, failing under an 85% floor (it currently sits near 90%) |
| [`integration.yml`](.github/workflows/integration.yml) | `integration` | The sandbox integration suite, on pushes to `master`, nightly and on demand |

`ci.yml` runs on every push and pull request, needs no secrets, and cancels superseded runs on
every branch except `master`.

The integration workflow is deliberately kept out of the pull-request gate: forks cannot read
repository secrets, and the booking tests should not run once per push per PHP version. It is
serialised through a concurrency group for the same reason.

To enable it, add `CARGOBOARD_API_KEY` (a **sandbox** key) to a repository **environment** named
`cargoboard-sandbox`, restricted to the `master` branch. Environment secrets are only readable by
jobs that opt into that environment, so a workflow added on a side branch cannot reach them.
Without it the job reports "not configured" and stops rather than failing the build. Running it
manually (`workflow_dispatch`) offers a checkbox to skip the order-placing tests.

The suite is attempted up to three times with a minute between attempts. That is not papering
over flaky tests: the sandbox degrades under load and starts answering with connection resets,
connect timeouts and `SSL_read: unexpected eof` — on a different test each time, and never with a
failed assertion. A rested sandbox runs the whole suite green. If all three attempts fail on the
*same* tests, that is a real regression.

---

## License

[Apache License 2.0](LICENSE), see [NOTICE](NOTICE) for attribution requirements.

You may use, distribute, and modify this library freely. You must retain the `NOTICE` file and copyright notices in any redistribution or derivative work.

---

*Built by [Very Code](https://very-code.com). Contributions welcome, open an issue or PR.*
