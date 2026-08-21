# API Reference

Every public method of `very-code-com/cargoboard-php`, the DTOs it takes and returns, the local
validation rules, and the exception hierarchy.

- [Client construction](#client-construction)
- [Quotations](#quotations)
- [Orders](#orders)
- [Documents](#documents)
- [Tracking](#tracking)
- [Invoices](#invoices)
- [Dangerous goods](#dangerous-goods)
- [Listing and filtering](#listing-and-filtering)
- [Request DTOs](#request-dtos)
- [Response DTOs](#response-dtos)
- [Enums](#enums)
- [Local validation rules](#local-validation-rules)
- [Exceptions](#exceptions)
- [Webhooks and tracking links](#webhooks-and-tracking-links)

---

## Client construction

```php
new CargoboardClient(
    config:    CargoboardConfig,
    transport: TransportInterface       = new CurlTransport(),
    logger:    ?Psr\Log\LoggerInterface = null,
)

CargoboardClient::sandbox(string $apiKey): self
CargoboardClient::production(string $apiKey): self
```

| Method | Returns | Notes |
|--------|---------|-------|
| `withParcelMode(bool $enabled = true)` | `CargoboardClient` | A copy that sends the parcel-mode header and applies the parcel rules. The original is untouched. |
| `config()` | `CargoboardConfig` | The configuration in use. |
| `isParcelMode()` | `bool` | Whether this client is in parcel mode. |
| `validateLocally(ShipmentRequest, ValidationMode = Order, ?bool $parcelMode = null)` | `list<string>` | Runs every local rule without any network call. Empty array means valid. |
| `warningsFor(ShipmentRequest, ValidationMode = Order)` | `list<string>` | Things Cargoboard accepts and then sorts out by hand. Never thrown; logged at warning level on every quote and booking. |

`CargoboardConfig` is built with `::sandbox()`, `::production()`, `::fromEnv()` or `::fromArray()`;
see the [README](../README.md#configuration) for the environment variables and array keys.

---

## Quotations

| Method | Endpoint | Returns |
|--------|----------|---------|
| `quote(ShipmentRequest)` | `POST /v1/quotations` | `QuotationResult` |
| `listQuotations(?ListQuery)` | `GET /v1/quotations` | `QuotationPage` |
| `fetchQuotation(string $id)` | `GET /v1/quotations/{id}` | `Quotation` |
| `bookQuotation(string $quotationId, ShipmentRequest)` | `POST /v1/quotations/{id}/book` | `OrderResult` |

`quote()` validates in **quotation mode** (post code + country are enough); `bookQuotation()`
validates in **booking mode** (names, streets, cities and a pickup date are required too).

Booking through a quotation id guarantees the quoted price. `placeOrder()` re-prices at booking
time.

---

## Orders

| Method | Endpoint | Returns |
|--------|----------|---------|
| `placeOrder(ShipmentRequest)` | `POST /v1/orders` | `OrderResult` |
| `listOrders(?ListQuery)` | `GET /v1/orders` | `OrderPage` |
| `fetchOrder(string $id)` | `GET /v1/orders/{id}` | `Order` |
| `cancelOrder(string $id)` | `POST /v1/orders/{id}/cancel` | `CancelResult` |

Wherever a method takes `$id`, Cargoboard accepts **either** the order's CUID
(`cm3328igs04jbqt0dluurnxyl`) **or** its shipment reference (`10374504`).

Cancelling an order that has already been collected fails with `CargoboardConflictException`.

---

## Documents

| Method | Endpoint | Returns |
|--------|----------|---------|
| `fetchLabels(string $id, LabelFormat = A4)` | `GET /v1/orders/{id}/print-shipment-labels` | `string` (raw PDF bytes) |
| `fetchConfirmation(string $id)` | `GET /v1/orders/{id}/print-confirmation` | `string` (raw PDF bytes) |
| `fetchInvoicePdf(string $id)` | `GET /v1/invoices/{id}/pdf` | `string` (raw PDF bytes) |

`LabelFormat::A4` puts several labels on one sheet (office printer); `LabelFormat::A6` emits one
label per page (label printer).

A 2xx response that is not a PDF raises `CargoboardResponseParseException` rather than writing a
broken file; an error status is mapped to the usual exception types even though these endpoints
answer errors as JSON.

---

## Tracking

| Method | Endpoint | Returns |
|--------|----------|---------|
| `fetchTracking(string $id)` | `GET /v1/orders/{id}/tracking` | `TrackingResult` |

`TrackingResult` carries both views Cargoboard returns, plus a tidied form of the event feed:

```php
$tracking->steps          // list<TrackingStep>  - the milestone chain, reached and unreached
$tracking->events         // list<TrackingEvent> - the raw status feed, in the API's own order
$tracking->timeline()     // list<TrackingEvent> - the same feed, deduplicated and chronological

$tracking->currentStep()      // ?TrackingStep - furthest milestone actually reached
$tracking->step(TrackingStepType::Delivered)
$tracking->isDelivered()      // bool
$tracking->hasWarning()       // bool - any milestone came back as a warning
$tracking->latestEvent()      // ?TrackingEvent
$tracking->latestDeliveryWindow() // ?TrackingWindow - newest estimate on the feed
$tracking->latestPickupWindow()   // ?TrackingWindow
$tracking->estimatedDelivery()// ?array{from: ?DateTimeImmutable, until: ?DateTimeImmutable}
$tracking->signedBy()         // ?string - from the newest proof-of-delivery event
```

### Raw feed or tidied feed

`events` is the response untouched. `timeline()` is what you want before storing or rendering:
deduplicated on the event id and sorted oldest first.

```php
foreach ($tracking->timeline() as $event) {
    printf("%-5s %s\n", $event->code, $event->describe());
}
```

### Displaying an event

**Do not** write `$event->label ?? $event->message ?? $event->code`. `label` is not part of this
endpoint's schema and live accounts never send it, and around a third of events have no
`message` either — so that chain prints a bare `540` as the description of event 540. Those
"empty" events are not empty: they carry the refined collection and delivery windows, which is
the most useful thing on the feed.

```php
$event->describe(?DateTimeZone)        // string - text, else the estimates, else "Status {code}"
$event->estimateSummary(?DateTimeZone) // ?string - just the estimates half
$event->pickupWindow()                 // ?TrackingWindow
$event->deliveryWindow()               // ?TrackingWindow
$event->hasEstimates()                 // bool
```

`describe()` never invents a meaning for a status code: with ~450 network codes published as a
spreadsheet, `Status 809` is the honest fallback. The windows are UTC as the API sends them;
pass a `DateTimeZone` to render local time.

`TrackingWindow` (`from`, `until`) formats itself day-first: `18.08.2026 07:00-15:00` within one
day, `19.08.2026 06:00 - 21.08.2026 14:00` across days, `from 18.08.2026 07:00` when only one
end is known.

### Storing events

```php
$event->id            // ?string - undocumented, but sent on every live event
$event->fingerprint() // string  - the id, or a composite when the API sends none
```

`(code, originatedAt)` is **not** a unique key. A shipment notified by both SMS and e-mail
produces several `722` events sharing a code and a timestamp, so keying on that pair silently
drops one and, on a repair pass, overwrites one event's row with another's text. Key on `id`.

`WebhookEvent` offers the same `describe()`, `pickupWindow()`, `deliveryWindow()` and
`hasEstimates()`, so a webhook-driven history and a polled one render identically.

---

## Invoices

| Method | Endpoint | Returns |
|--------|----------|---------|
| `listInvoices(?ListQuery)` | `GET /v1/invoices` | `InvoicePage` |
| `fetchInvoicePdf(string $id)` | `GET /v1/invoices/{id}/pdf` | `string` |

This endpoint **requires** `take`, so the client always sends one (`ListQuery::MAX_TAKE`, 50).
Pass `Invoice::pdfId()` to `fetchInvoicePdf()`.

Invoice access is a **separate account entitlement**: a key that quotes, books and tracks
normally can still be refused here with `403 Forbidden resource`. A 403 from this endpoint alone
does not mean the key is bad.

---

## Dangerous goods

| Method | Endpoint | Returns |
|--------|----------|---------|
| `fetchAdrData(string $unNumber)` | `GET /v1/dangerous-goods/un-numbers/{unNumber}` | `AdrData` |

```php
$adr  = $client->fetchAdrData('3480');
$good = $adr->toDangerousGood(
    quantity: 1, weightGross: 11.0, weightNetOrVolume: 9.0, packageType: 'Kiste',
);
```

`toDangerousGood()` carries every classification field across and computes `pointsTotal` from the
transport-category multiplier, so no local ADR table is needed.

**Retry on 202.** Cargoboard answers a UN number it has not cached yet with HTTP 202 and a body
carrying no ADR fields at all, having queued a background sync. Because 202 is a success status,
the client checks for it explicitly and throws `CargoboardAdrSyncPendingException` rather than
handing back an `AdrData` whose every field is `null` — silently declaring a dangerous good with
no hazard class is the one outcome this endpoint must never produce.

```php
try {
    $adr = $client->fetchAdrData('3480');
} catch (CargoboardAdrSyncPendingException $e) {
    // $e->unNumber === '3480'; retry the same lookup shortly.
}
```

On the sandbox this is the normal answer for every UN number, including nonexistent ones: its ADR
table is seeded lazily and the sync does not complete, so the endpoint cannot tell "unknown" from
"not fetched yet" there.

---

## Listing and filtering

`ListQuery` is immutable and fluent:

```php
ListQuery::create()
    ->take(25)
    ->cursor($previousPage->nextCursor())
    ->whereEquals('shipmentStatus', 'DELIVERED')
    ->where('createdAt>="2026-01-01"')     // raw expression
    ->operator(FilterOperator::And)
    ->orderByDesc('sequence')
    ->withTotal();
```

| Parameter | Method | Notes |
|-----------|--------|-------|
| `take` | `take(int)` | Page size, max 50 (`ListQuery::MAX_TAKE`). Required by `/v1/orders` and `/v1/invoices`, optional on `/v1/quotations`; the client supplies it where it is required. |
| `cursor` | `cursor(?float)` | The previous page's last `sequence`, from `nextCursor()`. |
| `orderBy` | `orderBy()`, `orderByDesc()`, `orderByRaw()` | Repeated parameter. |
| `filter` | `whereEquals()`, `where()` | Repeated parameter, `field="value"` expressions. |
| `filterOperator` | `operator(FilterOperator)` | `AND` (default) or `OR`. |
| `total` | `withTotal(bool = true)` | Adds the total row count to the page. |

Cargoboard publishes one filter example (`postCodeFrom="33100"`) and no list of filterable
fields, so `where()` exists for anything equality cannot express.

All three page objects (`OrderPage`, `QuotationPage`, `InvoicePage`) are `Countable` and
`IteratorAggregate`, and expose `first()`, `isEmpty()`, `nextCursor()` and `$total`.

---

## Request DTOs

```
ShipmentRequest
├── product: Product
├── shipper: Shipper
│   ├── address: Address              (postCode + countryCode required)
│   ├── contactPerson?: ContactPerson
│   ├── neutralData?: NeutralData
│   └── pickupOn | pickupAtFrom + pickupAtUntil
├── consignee: Consignee
│   ├── address: Address
│   ├── deliveryOn?                   (FIX products only)
│   └── deliveryTimeSlot?: DeliveryTimeSlot
└── lines: list<Line>
    └── dangerousGoods?: list<DangerousGood>
```

`ShipmentRequest` helpers: `totalWeightKg()`, `totalVolumeM3()`, `totalUnits()`,
`hasDangerousGoods()`, `crossesCustomsBorder()`, `withProduct()`, `withCustomerOrderCode()`.

`Line` helpers and named constructors:

```php
Line::loadingMetres(loadingMetres: 7.2, totalWeightKg: 5800.0, heightCm: 250);
Line::vehicle(PackageType::DirectTruck12, totalWeightKg: 4800.0);

$line->totalWeightKg();
$line->unitVolumeM3();  $line->totalVolumeM3();
$line->unitVolumetricWeightKg();   // (L x W x H) / 5000, the parcel formula
$line->sortedSides();  $line->girthCm();
$line->loadingMetreValue();        // null unless the line is PARTIE
```

Date fields accept a `string` or any `DateTimeInterface`. Timestamps are converted to UTC on the
way out, because Cargoboard reads a naive timestamp as UTC.

Every request DTO has a `toArray()` that omits unset fields, so `null` means "let Cargoboard's
default apply" rather than "send null". This matters for `wantsClimateContribution`, which
Cargoboard defaults to **true**.

---

## Response DTOs

| DTO | Returned by | Highlights |
|-----|-------------|------------|
| `QuotationResult` | `quote()` | `id`, `price`, `priceStandard`, `runtime`, `delivery`, `costItems`, `co2Emission`, `freightCost()`, `costItemsOfType()`, `costItemsTotal()`, `bookingUrl()` |
| `OrderResult` | `placeOrder()`, `bookQuotation()` | The above plus `reference`, `platformTrackingUrl`, `trackingApiUrl()` |
| `Order` | `fetchOrder()`, `listOrders()` | The stored record: `status`, `shipmentStatus`, `lines` (with `barcodes()`), `easybillInvoices`, partners, `actualPrice`, `price()`, `runtime()`, `co2Emission()`, `isDelivered()`, `isCancelled()`, `needsConfirmation()` |
| `Quotation` | `fetchQuotation()`, `listQuotations()` | The stored quotation, plus `orderId` and `isBooked()` |
| `TrackingResult` | `fetchTracking()` | See [Tracking](#tracking) |
| `Invoice` | `listInvoices()`, `Order::$easybillInvoices` | `documentNumber`, `documentAmount`, `dueDate`, `isPaid`, `isOverdue()`, `isCancelled()`, `pdfId()` |
| `AdrData` | `fetchAdrData()` | `toDangerousGood()`, `packagingInstructionList()`, `specialRegulationList()`, `hasSpecialProvision188()` |
| `CancelResult` | `cancelOrder()` | `status`, `message` |

Supporting value objects: `Price` (`amountInCents()`, `__toString()`), `VatPart`, `CostItem`,
`OrderCostItem`, `Runtime`, `DeliveryWindow`, `Co2Emission`, `Link`, `Barcode`, `OrderLine`,
`TrackingStep`, `TrackingEvent`, `TrackingWindow`, `TrackingLocation`, `TrackingLocationAddress`.

Parsing is deliberately lenient: an unrecognised enum value becomes `null` (with the raw string
kept on cost items as `rawType` / `rawSubtype`) rather than throwing, so a new status code on
Cargoboard's side cannot break a running integration.

---

## Enums

| Enum | Values |
|------|--------|
| `Product` | `STANDARD`, `EXPRESS`, `EXPRESS_8/10/12/16`, `FIX`, `FIX_8/10/12/16`, `DIRECT`. Helpers: `isFix()`, `isExpress()`, `deliveryDeadlineHour()` |
| `PackageType` | `EP`, `FP`, `GB`, `KI`, `KT`, `PA`, `CC`, `PARTIE`, and the `DIRECT_*` vehicles. Helpers: `isVehicle()`, `isLoadingMetres()`, `truckType()` |
| `TruckType` | 5 vehicles. Helpers: `maxPayloadKg()`, `allowedProducts()` |
| `CountryCode` | The 32 countries Cargoboard serves. Helpers: `fromString()`, `isEuCustomsTerritory()` |
| `LoadingType` | `RAMP`, `SIDE`, `CRANE`, `LIFTING_PLATFORM_OR_TAIL_LIFT_TRUCK` |
| `Incoterm` | `STANDARD`, `DAP_CLEARED`, `DAP_UNCLEARED`. Helper: `isDap()` |
| `LabelFormat` | `A4`, `A6` |
| `CostItemType` / `CostItemSubtype` | The full surcharge taxonomy |
| `TransportType` | `GROUPAGE`, `PART_LOAD`, `DIRECT` (response only) |
| `OrderStatus` / `QuotationStatus` | Booking state |
| `ShipmentStatus` | Physical state. Helper: `isFinal()` |
| `TrackingStepType` / `TrackingStepStatus` | Milestones. Helpers: `order()`, `isReached()` |
| `PaymentMethod`, `RefundType`, `TariffCategory`, `FilterOperator` | |

---

## Local validation rules

Run before every `quote()`, `placeOrder()` and `bookQuotation()`, and available on demand
through `validateLocally()`. Messages are field-path prefixed the way Cargoboard's own 422
messages are.

**Addresses**

- `postCode` and `countryCode` are always required.
- Booking mode additionally requires `name`, `street` and `city` on both parties, and a pickup date.

**Dates**

- Pickups and deliveries are Monday to Friday.
- A pickup window needs both ends, and `pickupAtFrom` must not be later than `pickupAtUntil`.
- If `pickupOn` and the window are both sent, their date part must match.
- `deliveryOn` is only valid on a FIX product, and must not precede the pickup.

**Lines**

- At least one line; `content`, `unitQuantity ≥ 1` and positive dimensions and weight.

**Loading metres (`PARTIE`)**

- `unitWidth` = 240, `unitHeight` ∈ {250, 260, 300}, `unitQuantity` = 1.

**Vehicles (`DIRECT_*`)**

- Exactly one vehicle line, not mixed with package lines, `unitQuantity` = 1.
- `DIRECT_TRUCK_40` → `STANDARD` or `EXPRESS`; every other vehicle → `DIRECT`.
- Payload ceilings: 1000 / 1000 / 2500 / 5000 / 24000 kg.

**Insurance and value**

- `wantsInsurance` requires a positive `valueOfGoodsAmount`.
- `valueOfGoodsCurrency` must be `EUR`.

**Dangerous goods**

- Each declaration needs `unNo` and `substanceName`.

**Parcel mode only** (see `Validation\ParcelLimits`)

- Package type `KT`; 32 kg physical **and** volumetric ((L×W×H)/5000).
- Longest side ≤ 100 cm, second-longest ≤ 76 cm, girth (L + 2W + 2H) ≤ 300 cm.
- At most 20 parcels per pickup location per day.
- Insured value ≤ EUR 40 000.
- No dangerous goods, no `neutralData`.
- The lane must start or end in Germany (no EU→EU).

### Warnings, not errors

Some rules Cargoboard enforces operationally rather than with an HTTP status: the API accepts
the booking, and someone at a depot deals with the consequences. Refusing those bookings locally
would be this library overruling the API, so they are returned by `warningsFor()` and logged at
warning level instead of thrown.

- A consignee with `isPrivateCustomer` but neither `wantsContactBeforeDelivery` nor
  `wantsDeliveryWithoutConsigneePresence`. Per Cargoboard support, the private-customer flag on
  its own is not sufficient: a B2C delivery needs either an appointment call or permission to
  leave the goods, or it runs into trouble at the delivery depot.

---

## Exceptions

```
CargoboardException                          base; getRawResponse(), getDebugReport()
├── CargoboardValidationException            local rules failed, nothing was sent; ->errors
├── CargoboardTransportException             network, DNS, TLS, timeout
├── CargoboardResponseParseException         unreadable or unexpected response
└── CargoboardApiException                   Cargoboard answered with an error status
    │                                        ->statusCode, ->errors, ->error,
    │                                        hasError(), getFieldNames()
    ├── CargoboardAuthException              401 / 403; isMissingCredentials()
    ├── CargoboardAdrSyncPendingException    202 from the ADR lookup; ->unNumber
    ├── CargoboardNotFoundException          404
    ├── CargoboardConflictException          409
    ├── CargoboardUnprocessableEntityException  422
    ├── CargoboardRateLimitException         429; retryAfterSeconds()
    └── CargoboardServerException            5xx; isRetryable() for 502/503/504
```

Catch the narrowest type that describes what you want to do about the failure; the subtypes must
come before `CargoboardApiException` in a catch chain.

With `debug` enabled the raw response body is attached to every exception and a full debug report
(message + raw body + stack trace) is logged through the injected PSR-3 logger.

---

## Webhooks and tracking links

```php
// Track & Trace webhook payload
$event = WebhookEvent::fromJson($rawRequestBody);
$event->reference;          // Cargoboard's shipment number
$event->customerOrderCode;  // your own reference
$event->statusCode;  $event->label;  $event->originatedAt;
$event->eventId;            // the deduplication key - see Tracking, the same trap applies here
$event->describe();         // the display line, identical in behaviour to TrackingEvent's
$event->pickupWindow();  $event->deliveryWindow();  $event->hasEstimates();
$event->isProduction();  $event->isProofOfDelivery();  $event->hasDeliveryEstimate();
$event->raw;                // the payload verbatim

// Customer-facing tracking pages
TrackingUrl::forOrder($orderResult, $consigneePostCode);  // recommended, no captcha
TrackingUrl::forStoredOrder($order);                      // same, for a fetched Order
TrackingUrl::forOrderId($orderId, $consigneePostCode);
TrackingUrl::forReference($reference, $consigneePostCode, 'de');  // legacy, captcha
```

Webhooks are registered by Cargoboard, not through the API: send your HTTPS endpoint URL, any
auth details and your customer number to <api@cargoboard.com>. They are not signed, so
authenticate them the way you agreed when registering the endpoint, and make the handler
idempotent (`event.id` is the natural deduplication key).
