# Changelog

All notable changes to `very-code-com/cargoboard-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

Nothing yet.

---

## [1.0.0], 2026-08-18

Initial release. Verified end to end against the live Cargoboard sandbox: quotation, booking,
order retrieval, tracking, label and confirmation PDFs, and cancellation.

### Live-API behaviours handled

These are answers the published schemas do not describe, found by running the client against the
real sandbox rather than against fixtures:

- `GET /v1/dangerous-goods/un-numbers/{unNumber}` answers **202** with an empty body when it has
  not cached a UN number yet. As a success status it would have parsed into an all-null `AdrData`
  and produced a dangerous-goods declaration with no hazard class; `fetchAdrData()` throws
  `CargoboardAdrSyncPendingException` instead.
- `GET /v1/orders` requires `take` and answers 422 without it, exactly like `GET /v1/invoices`
  and unlike `GET /v1/quotations`. `listOrders()` now supplies `ListQuery::MAX_TAKE` when the
  caller gives no page size, so an empty query works on every list endpoint.
- `take` is capped at 50, exposed as `ListQuery::MAX_TAKE`.

### Added

- `CargoboardClient`: main API client with named constructors `::sandbox()` / `::production()`,
  covering every endpoint of Cargoboard's public API
- `quote()`: `POST /v1/quotations`, a binding price with the full cost breakdown, transit time,
  delivery window and CO2 figures
- `listQuotations()` / `fetchQuotation()`: `GET /v1/quotations` and `/v1/quotations/{id}`
- `bookQuotation()`: `POST /v1/quotations/{id}/book`, booking at the quoted price rather than
  re-pricing
- `placeOrder()`: `POST /v1/orders`, pricing and booking in one call
- `listOrders()` / `fetchOrder()`: `GET /v1/orders` and `/v1/orders/{id}`, by CUID or by shipment
  reference
- `cancelOrder()`: `POST /v1/orders/{id}/cancel`
- `fetchLabels()` / `fetchConfirmation()`: the shipment label (A4 or A6) and order confirmation
  PDFs, returned as raw bytes
- `fetchTracking()`: `GET /v1/orders/{id}/tracking`, exposing both the milestone chain and the
  raw status event history, with helpers for the current step, the refined delivery estimate and
  the proof-of-delivery signer
- `listInvoices()` / `fetchInvoicePdf()`: `GET /v1/invoices` and `/v1/invoices/{id}/pdf`
- `fetchAdrData()`: `GET /v1/dangerous-goods/un-numbers/{unNumber}`, plus
  `AdrData::toDangerousGood()` which turns the lookup straight into a shipment declaration, so no
  local ADR database is needed
- `validateLocally()`: the full local rule set on demand, with no network call
- `withParcelMode()`: an immutable client copy that sends
  `x-transport-type-parcel-is-active: true` and applies the parcel-specific rules
- `CargoboardConfig` with `::sandbox()` / `::production()` / `::fromEnv()` / `::fromArray()`
  factories, per-environment base URLs, timeouts, parcel mode and a debug flag
- `Internal\Validator\ShipmentValidator`: local pre-flight validation of Cargoboard's documented
  business rules, in two modes because a quotation and a booking share one request body but not
  one set of mandatory fields. Covers the address rules, Monday-to-Friday pickups and deliveries,
  the `pickupOn`/`pickupAtFrom`/`pickupAtUntil` consistency rule, `deliveryOn` being FIX-only,
  per-line content and dimensions, loading-metre geometry (240 cm wide, 250/260/300 cm high, one
  block), vehicle payload ceilings and their required products, insurance needing a goods value,
  EUR-only goods values, dangerous-goods declarations, and the full parcel rule set (32 kg
  physical and volumetric, 100/76 cm sides, 300 cm girth, 20 parcels per pickup per day,
  EUR 40 000 insured value, no ADR, no neutral address, no EU-to-EU lane)
- `Internal\Http\RequestBuilder`: builds every request, including the repeated `filter=`/`orderBy=`
  query parameters that `http_build_query()` would render in a shape Cargoboard rejects
- `Internal\Http\ErrorMapper`: maps HTTP status codes to the narrowest exception type, flattening
  NestJS error bodies (`message` as a string or an array) into a single list of messages
- `Internal\Json\ResponseParser` / `Value` / `DateFormat`: envelope unwrapping and lenient,
  type-safe field reading
- `TransportInterface` + `CurlTransport` + `TransportRequest`/`TransportResponse`: transport
  abstraction for easy test mocking, with response headers, PDF detection and
  `TransportRequest::redacted()` for safe logging
- Full exception hierarchy: `CargoboardException` -> `CargoboardValidationException`,
  `CargoboardTransportException`, `CargoboardResponseParseException` and `CargoboardApiException`,
  the latter specialised into `CargoboardAuthException` (401/403),
  `CargoboardNotFoundException` (404), `CargoboardConflictException` (409),
  `CargoboardUnprocessableEntityException` (422, with per-field messages and `getFieldNames()`),
  `CargoboardRateLimitException` (429, with `retryAfterSeconds()`),
  `CargoboardServerException` (5xx, with `isRetryable()`) and
  `CargoboardAdrSyncPendingException` (202 from the ADR lookup, with `->unNumber`)
- `Query\ListQuery`: immutable, fluent query builder for the list endpoints (page size, cursor,
  sorting, `field="value"` filters, filter operator, total)
- `Webhook\WebhookEvent`: parser for the Track & Trace webhook payload, with
  `isProduction()`, `isProofOfDelivery()` and `hasDeliveryEstimate()`
- `Tracking\TrackingUrl`: builders for both customer-facing tracking links, the direct-unlock
  order-id link and the legacy captcha-gated reference link
- 21 PHP 8.1+ backed enums: `Product`, `PackageType`, `TruckType`, `CountryCode`, `LoadingType`,
  `Incoterm`, `LabelFormat`, `CostItemType`, `CostItemSubtype`, `TransportType`, `OrderStatus`,
  `QuotationStatus`, `ShipmentStatus`, `PaymentMethod`, `RefundType`, `TariffCategory`,
  `TrackingStepType`, `TrackingStepStatus`, `FilterOperator`, plus `ValidationMode` and the
  `ParcelLimits` constants
- ~30 typed DTOs mirroring the API, with `Line::loadingMetres()` and `Line::vehicle()` named
  constructors and computed helpers (`totalWeightKg()`, `unitVolumetricWeightKg()`, `girthCm()`,
  `loadingMetreValue()`, `crossesCustomsBorder()`, ...)
- PSR-3 logger injection with `NullLogger` as default
- 192 unit tests (603 assertions) plus a 17-test integration suite against the sandbox (skipped
  unless `CARGOBOARD_SANDBOX=1` and a key are set)
- Ten runnable examples in [`examples/`](examples/), three of which need no key and no network;
  documentation in [`docs/`](docs/)
- GitHub Actions CI: unit tests on PHP 8.2/8.3/8.4 plus a `--prefer-lowest` leg verifying the
  declared dependency floor, PHPStan level 8, `composer validate --strict --check-lock`,
  `composer audit` and an 85% line-coverage floor on every push; a separate nightly/manual
  workflow runs the sandbox integration
  suite, serialised through a concurrency group

### Notes

Cargoboard's API has a few behaviours that are worth knowing before integrating; all of them are
handled by this library and documented in [docs/CARGOBOARD-NOTES.md](docs/CARGOBOARD-NOTES.md).

- **Keys are per environment.** A production key sent to `api-sandbox.cargoboard.com` is rejected
  with `403 Forbidden resource`, which is exactly what an invalid key returns. The sandbox also
  answers 403 when no key is sent at all, rather than the documented 401, so a 403 alone does not
  tell you which of the three is wrong. `CargoboardAuthException` therefore appends the possible
  causes to its message.
- **A quotation and a booking share one request body but not one set of mandatory fields**, which
  is why there is one `ShipmentRequest` DTO and two validation modes.
- **Parcel bookings are selected by a header**, `x-transport-type-parcel-is-active`. Without it a
  parcel-shaped payload is silently priced and booked as freight, so parcel mode is modelled as a
  distinct client instance rather than as a payload flag.
- **Every order has two identifiers**: the CUID (`id`) and the shipment reference (`reference`).
  The API accepts either wherever it takes an `{id}`, but the `orderTrack` link is the one place
  that uses the reference while the others use the CUID.
- **The ADR endpoint is documented with two different field vocabularies** (`unNo`/`substanceName`
  in the OpenAPI schema, `unNumber`/`properShippingName`/`labels`/`flags` in the worked example).
  `AdrData` reads whichever is present.
- **Some live fields are missing from the published schemas** (`platformTrackingUrl`,
  `price.grossAmount`/`vatAmount`, `costItems[].pricePartVat`), and the documented example
  response uses a cost item type that is not in the schema's enum
  (`CLIMATE_COMPENSATION_SURCHARGE`). Unrecognised enum values parse to `null` with the raw string
  preserved, so schema drift cannot break a running integration.
- **List endpoints paginate by cursor**, and the cursor is the last row's `sequence`. Their
  `orderBy` and `filter` parameters are repeated, not bracketed arrays. `GET /v1/invoices`
  requires `take`, unlike the other two.
- **Tracking status codes are not an enum here.** There are some 450 of them and their meanings
  are published as a Google Sheet rather than in the API reference, so `TrackingEvent::$code` is a
  string; branch on the `TrackingStep` milestones for logic.
- **Webhooks are not signed.** Cargoboard documents no signature or shared secret, so authenticate
  the endpoint the way you agreed when registering it and make the handler idempotent.

[Unreleased]: https://github.com/very-code-com/cargoboard-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/very-code-com/cargoboard-php/releases/tag/v1.0.0
