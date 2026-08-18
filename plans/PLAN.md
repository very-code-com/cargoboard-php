# cargoboard-php - build plan and reference material

## Goal

A complete, idiomatic PHP client for the Cargoboard API, matching the shape and quality bar of
`very-code-com/suus-php` and `very-code-com/dpd-de-php`: full endpoint coverage, typed DTOs and
enums, local business-rule validation, a testable transport seam, a real test suite, runnable
examples, documentation and CI.

## Source material

Cargoboard's documentation is served twice: as human pages at <https://docs.cargoboard.com> and,
for every reference page, as a Markdown twin at `<page-url>.md` that contains the **full OpenAPI
definition** for that endpoint. The machine-readable index of all pages is at
<https://docs.cargoboard.com/llms.txt>.

Both are archived here:

- [`reference/llms.txt`](reference/llms.txt) - the page index as of 2026-08-17
- [`reference/openapi/*.json`](reference/openapi) - the OpenAPI definition extracted from every
  reference page (25 files), which is what the DTOs, enums and endpoint list were built from

Regenerate them with:

```bash
curl -s https://docs.cargoboard.com/llms.txt -o plans/reference/llms.txt
grep -oE 'https://docs\.cargoboard\.com/[a-z]+/[a-z0-9-]+\.md' plans/reference/llms.txt | sort -u |
while read -r url; do
  name=$(basename "$url" .md)
  curl -s "$url" | awk '/^```json$/{f=1;next}/^```$/{if(f)exit}f' > "plans/reference/openapi/${name}.json"
done
find plans/reference/openapi -size -2 -delete   # pages without an OpenAPI block
```

## Endpoint inventory

| # | Endpoint | Client method | Status |
|---|----------|---------------|--------|
| 1 | `POST /v1/quotations` | `quote()` | done |
| 2 | `GET /v1/quotations` | `listQuotations()` | done |
| 3 | `GET /v1/quotations/{id}` | `fetchQuotation()` | done |
| 4 | `POST /v1/quotations/{id}/book` | `bookQuotation()` | done |
| 5 | `POST /v1/orders` | `placeOrder()` | done |
| 6 | `GET /v1/orders` | `listOrders()` | done |
| 7 | `GET /v1/orders/{id}` | `fetchOrder()` | done |
| 8 | `POST /v1/orders/{id}/cancel` | `cancelOrder()` | done |
| 9 | `GET /v1/orders/{id}/print-shipment-labels` | `fetchLabels()` | done |
| 10 | `GET /v1/orders/{id}/print-confirmation` | `fetchConfirmation()` | done |
| 11 | `GET /v1/orders/{id}/tracking` | `fetchTracking()` | done |
| 12 | `GET /v1/invoices` | `listInvoices()` | done |
| 13 | `GET /v1/invoices/{id}/pdf` | `fetchInvoicePdf()` | done |
| 14 | `GET /v1/dangerous-goods/un-numbers/{unNumber}` | `fetchAdrData()` | done |

Not an endpoint, but part of the integration surface:

| Feature | Where |
|---------|-------|
| Track & Trace webhook payload | `Webhook\WebhookEvent` |
| Customer tracking links | `Tracking\TrackingUrl` |
| Parcel mode header | `CargoboardClient::withParcelMode()` |

## Design decisions

1. **One `ShipmentRequest` DTO, two validation modes.** Cargoboard uses an identical request body
   for quotations and bookings but requires more of it for a booking. Two DTOs would duplicate
   ~20 fields and force callers to convert between them; one DTO plus `ValidationMode` keeps the
   natural "price it, then book the same thing" workflow.
2. **Parcel mode as a client clone, not a request flag.** It is a transport header, it changes
   which rules apply, and forgetting it silently produces a freight booking. Making it visible at
   the call site (`$client->withParcelMode()->placeOrder(...)`) is worth the extra object.
3. **Lenient response parsing.** Live responses already drift from the published schemas, so
   unknown enum members become `null` (with the raw string kept) and missing fields never throw.
   A new status code on Cargoboard's side must not break a running integration.
4. **Local validation of documented rules only.** Everything the validator enforces is stated in
   Cargoboard's own documentation. It never guesses at limits they have not published.
5. **`TransportInterface` as the only seam.** One interface to fake covers every endpoint, which
   is what makes 192 unit tests possible without a network.

## Verification status

- PHPStan level 8: clean, no baseline, no ignores.
- Unit suite: 192 tests / 603 assertions, no network.
- Integration suite: 17 tests against `api-sandbox.cargoboard.com`, skipped unless
  `CARGOBOARD_SANDBOX=1` and a key are set.
- **Live sandbox verification is done.** The full order flow is confirmed end to end against the
  real API: quotation, booking, order retrieval by CUID and by reference, tracking, label and
  confirmation PDFs, and cancellation. Parcel mode is pinned by a test that quotes the same
  payload twice and asserts the two prices differ, which is the cheapest proof that the
  `x-transport-type-parcel-is-active` header actually reaches the API.

Three live behaviours were found that the published schemas do not describe, all now handled:

1. `GET /v1/dangerous-goods/un-numbers/{unNumber}` answers **202** with no ADR fields when it has
   not cached a UN number yet. As a success status it parsed into an all-null `AdrData`, which
   would have produced a dangerous-goods declaration with no hazard class. Now
   `CargoboardAdrSyncPendingException`.
2. `GET /v1/orders` **requires `take`** and answers 422 without it, like `/v1/invoices` and unlike
   `/v1/quotations`. The client supplies one; the server's ceiling of 50 is `ListQuery::MAX_TAKE`.
3. Endpoint access is **per entitlement**: a key that quotes, books and tracks can still be
   refused by `GET /v1/invoices` with the same `403 Forbidden resource` that a bad key returns.

Two things remain unverifiable against the sandbox and are covered by fixtures only:

- **Invoices.** `listInvoices()` and `fetchInvoicePdf()` - the development key is not entitled to
  that endpoint.
- **Real ADR payloads.** The sandbox answers 202 for every UN number, including nonexistent ones,
  so no live ADR record has ever been parsed.

The sandbox also degrades under load, answering with connection resets, 10-second connect
timeouts and `SSL_read: unexpected eof` on a different test each time while a rested sandbox runs
green, which is why the integration workflow retries the suite rather than failing the build.
