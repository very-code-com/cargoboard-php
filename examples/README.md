# Examples

Runnable scripts demonstrating every part of the `very-code-com/cargoboard-php` client.

Set your **sandbox** key once, then run any script:

```bash
export CARGOBOARD_API_KEY=your-sandbox-key

php examples/01_quote_and_book.php
```

Cargoboard issues **separate keys per environment**, so a production key sent to the sandbox is
rejected with HTTP 403 exactly like an invalid one. Request a sandbox key from your Cargoboard
contact or <api@cargoboard.com>; see [Getting an API key](../README.md#getting-an-api-key).

Scripts 08, 09 and 10 need no key and no network at all.

| #  | Script | What it shows | Needs network? |
|----|--------|----------------|-----------------|
| 01 | [`01_quote_and_book.php`](01_quote_and_book.php) | The core workflow: price a shipment, then book that exact price with `bookQuotation()`. One `ShipmentRequest` for both calls, the full price breakdown, and every exception type handled | Yes (sandbox) |
| 02 | [`02_place_order_and_documents.php`](02_place_order_and_documents.php) | `placeOrder()` in one step, then the shipment labels (A4 and A6), the confirmation PDF, and cancelling the order again | Yes (sandbox) |
| 03 | [`03_tracking.php`](03_tracking.php) | Both tracking views (milestones and the event feed), the raw feed vs. `timeline()`, `describe()` for events that carry no message, the refined delivery ETA, the signer, and the two customer-facing tracking links | Yes (sandbox) |
| 04 | [`04_orders_and_invoices.php`](04_orders_and_invoices.php) | `ListQuery`: page size, filters, sorting, totals and cursor pagination. What a stored order carries (barcodes, partners, settled price), plus invoices and their PDFs | Yes (sandbox) |
| 05 | [`05_dangerous_goods.php`](05_dangerous_goods.php) | ADR lookup by UN number, turning it straight into a shipment declaration, the 1000-points rule, and why dangerous goods and parcel mode do not mix | Yes (sandbox) |
| 06 | [`06_parcel_shipments.php`](06_parcel_shipments.php) | `withParcelMode()`, every parcel limit including volumetric weight, and the parcel-only add-ons | Yes (sandbox) |
| 07 | [`07_loading_metres_and_vehicles.php`](07_loading_metres_and_vehicles.php) | `Line::loadingMetres()` for part loads and `Line::vehicle()` for a dedicated van or truck, with the product and payload rules for each | Yes (sandbox) |
| 08 | [`08_di_and_testing.php`](08_di_and_testing.php) | Dependency injection: a scripted fake `TransportInterface`, PSR-3 logging, credential redaction, and scripting an error path. The exact pattern the unit tests use | **No** |
| 09 | [`09_validation_and_errors.php`](09_validation_and_errors.php) | Every local business rule triggered in turn, quotation rules vs. booking rules, the warnings the API accepts but a depot has to sort out, and the whole exception hierarchy with what each type carries | **No** |
| 10 | [`10_webhook_endpoint.php`](10_webhook_endpoint.php) | A Track & Trace webhook endpoint: parsing the payload, matching it to your own order, rendering it with the same `describe()` the polling path uses, reacting to ETAs and proof of delivery, and the authentication caveat | **No** |

## Notes

- **Sandbox vs. production**: use `CargoboardClient::sandbox(...)` while developing. On the
  sandbox no truck is ever scheduled; on production every booking is a real, billable transport.
- **Quotation rules are looser than booking rules**: a quotation needs only a post code and a
  country on each side, a booking additionally needs names, streets, cities and a pickup date.
  The same request body serves both, which is why the client validates it in two modes.
- **Dimensions are per unit, in centimetres; weight is per unit, in kilograms.** Not totals.
  This is the single most common source of a surprising price.
- **`deliveryOn` is FIX-only.** Sending it on any other product is rejected.
- **Parcel mode is a header, not a payload flag.** Without `withParcelMode()` the very same
  request is priced and booked as freight; see example 06.
- **Unique references**: the booking examples derive `customerOrderCode` from the current
  timestamp so you can re-run them freely.
