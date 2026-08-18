# Cargoboard API notes

Things about Cargoboard's API that are not obvious from its reference documentation, found while
building this client. Each one is either handled by the library or worth knowing before you hit it.

- [Authentication and environments](#authentication-and-environments)
- [One request body, two sets of rules](#one-request-body-two-sets-of-rules)
- [Two identifiers per order](#two-identifiers-per-order)
- [Parcel mode is a header](#parcel-mode-is-a-header)
- [The response envelope is not uniform](#the-response-envelope-is-not-uniform)
- [The ADR endpoint has two documented shapes](#the-adr-endpoint-has-two-documented-shapes)
- [List endpoints](#list-endpoints)
- [Schema drift](#schema-drift)
- [Dimensions, weights and what they are per](#dimensions-weights-and-what-they-are-per)
- [Where the documentation lives](#where-the-documentation-lives)

---

## Authentication and environments

A single API key in the `X-API-KEY` header, and **separate keys per environment**:

| Environment | Base URL |
|-------------|----------|
| Sandbox (test system) | `https://api-sandbox.cargoboard.com` |
| Production | `https://api.cargoboard.com` |

A production key sent to the sandbox is rejected with `403 Forbidden resource`, which is exactly
what an invalid key returns. There is no message distinguishing the two cases, so
`CargoboardAuthException` appends that hint to its own message.

**A corrupted key is indistinguishable from every other failure**, and this is worth stating
plainly because it costs hours. A key with a single mistyped character in its JWT signature
returns the same `403 Forbidden resource`, byte for byte, as a missing key, a wrong-environment
key, an unactivated account, and an endpoint the key is not entitled to. There is no signal
anywhere in the status, body or headers to tell them apart. If every endpoint 403s, re-copy the
key character for character before investigating anything else.

**The sandbox does not distinguish a missing key from a bad one either.** Calling any `/v1/*`
endpoint with no `X-API-KEY` header at all returns `403 Forbidden resource`, not the `401` the
error-message documentation promises. In practice, when a key that should work returns 403 the
possibilities are: the key is not activated for API access, it belongs to the other environment,
or the account it belongs to is not a customer account that may quote and book (that last case
sometimes surfaces as `409 Authorized user is not a customer` instead).

A quick way to tell an unreachable host from a rejected key: `GET https://api-sandbox.cargoboard.com/`
is an unauthenticated health check and returns `{"status":"ok","service":"api","version":"..."}`.

Keys do not expire on their own. The key itself is a JWT carrying a `customer_id`, but it is used
as an opaque API key: there is no refresh flow and nothing to parse.

Some endpoints are documented as accepting "customer token (XApiKey) **or** user token (Bearer)".
This library only implements the API-key path, which is the one meant for system integrations.
Orders placed with a user token need a separate confirmation step, which is why `Order` carries
`isConfirmationNeeded` / `isConfirmed`.

---

## One request body, two sets of rules

Cargoboard says it plainly: *"The request body for quotation and booking requests is completely
identical. But for a quotation request, significantly less address information is mandatory than
for a booking request."*

| | Quotation | Booking |
|---|---|---|
| `address.postCode`, `address.countryCode` | required | required |
| `address.street`, `address.city` | optional | **required** |
| `name` | optional | **required** |
| pickup date | optional | **required** |

That asymmetry is why this library has one `ShipmentRequest` DTO and two `ValidationMode`s,
rather than two DTOs. The practical consequence: build the request once, price it, then book the
same object.

The schemas are not quite identical in one respect: `AddressQuotation` marks only
`postCode` + `countryCode` as required, while `AddressOrder` marks `street` and `city` too.
Everything else lines up.

---

## Two identifiers per order

Every order has both:

- `id` - an internal CUID, e.g. `cm3328igs04jbqt0dluurnxyl`.
- `reference` - the **shipment number**, e.g. `10374504`.

The reference is what Cargoboard, its network partners and the driver use; it is what belongs on
your paperwork and in support tickets. The API accepts **either** wherever it takes an `{id}`,
including the tracking, label, confirmation and cancel endpoints.

One place they are not interchangeable: the `orderTrack` link in a response's `links` array
addresses the shipment by **reference**, while every other link uses the CUID.

---

## Parcel mode is a header

`/v1/quotations` and `/v1/orders` book freight *or* parcels depending on one header:

```
x-transport-type-parcel-is-active: true
```

Without it, a parcel-shaped request is quietly priced and booked as an ordinary freight shipment.
There is nothing in the payload to say "this is a parcel", which makes it an easy mistake to make
and a hard one to notice. This library models it as a distinct client instance
(`$client->withParcelMode()`) so the choice is explicit at the call site, and switches on the
parcel-specific validation at the same time.

Parcel limits worth knowing before designing around them: 32 kg (physical **and** volumetric,
divisor 5000), 100 cm longest side, 76 cm second-longest, 300 cm girth, 20 parcels per pickup
location per day, EUR 40 000 insurable value, no dangerous goods (LQ and EQ included), no neutral
address, and no EU→EU lane that avoids Germany.

---

## The response envelope is not uniform

Most endpoints answer with `{"data": ..., "links": [...]}`, and the list endpoints add `"total"`.
But:

- `GET /v1/orders/{id}` and `GET /v1/quotations/{id}` are documented **without a response schema**
  at all. This client accepts either an enveloped or a bare object for them.
- `links` is declared in the schema as a single `HateoasLink` object, while every real response
  sends an **array** of them. Both shapes are parsed.
- The PDF endpoints return `application/pdf` on success but a JSON error body on failure, so a
  response has to be classified by status and content type rather than by endpoint.

---

## The ADR endpoint answers 202 when it has no data yet

`GET /v1/dangerous-goods/un-numbers/{unNumber}` is documented as returning 200/403/404/422. Live,
it also returns **202** with no ADR fields at all:

```json
{"statusCode":202,"code":"DANGEROUS_GOODS_UN_NUMBER_SYNC_QUEUED",
 "message":"Dangerous goods data for UN number 3480 is being synchronized. Retry later."}
```

202 is a success status, so the lenient response parsing this client uses everywhere else would
turn that body into an `AdrData` with an empty `unNo` and every other field `null` — and a caller
feeding it to `toDangerousGood()` would declare a dangerous good with no hazard class, no packing
group and no tunnel restriction, with nothing looking wrong. This is the one place where lenient
parsing is the wrong default, hence the explicit status check and
`CargoboardAdrSyncPendingException`.

On the sandbox every UN number answers 202, including nonexistent ones such as `9999`, and the
queued sync does not appear to complete: the sandbox ADR table is simply not populated. The path
itself still validates server-side — `unNumber` must be 1 to 4 digits, and `99999` is rejected
with 422.

---

## The ADR endpoint has two documented shapes

`GET /v1/dangerous-goods/un-numbers/{unNumber}` is documented twice, with different field names:

| Concept | OpenAPI schema | Worked example on the Dangerous Goods page |
|---------|----------------|--------------------------------------------|
| UN number | `unNo` | `unNumber` |
| Shipping name | `substanceName` | `properShippingName` |
| Hazard class | `riskMain` | `adrClass` + `labels.mainRisk` |
| Packing group | `packagingGroup` | `packingGroup` |
| Sub-risks | `riskAdditional1..3` | `labels.subRisks[]` |
| LQ eligibility | `isLimitedQuantityEligible` | `flags.limitedQuantity` |
| Extra fields | — | `adrRelease`, `packagingInstructions`, `generalSpecialRegulations`, `limitedQuantityCode`, `exemptedQuantityType`, `flags.*` |

`AdrData` reads whichever is present, so it works against either. The schema vocabulary matches
the one used for a shipment declaration (`DangerousGoodOrder`), which is what
`AdrData::toDangerousGood()` produces.

---

## List endpoints

- Pagination is by **cursor**, and the cursor is the last row's `sequence`, not an opaque token.
  `OrderPage::nextCursor()` and friends read it off for you.
- `orderBy` and `filter` are **repeated** query parameters (`filter=a&filter=b`). PHP's
  `http_build_query()` would render them as `filter[0]=a`, which the API's validation rejects, so
  this client builds the query string by hand.
- Filters are `field="value"` expressions, quotes included. Cargoboard publishes exactly one
  example (`postCodeFrom="33100"`) and no list of filterable fields or comparison operators, hence
  `ListQuery::where()` for raw expressions.
- **`take` is required on two of the three list endpoints, and the three disagree.** `GET /v1/orders`
  and `GET /v1/invoices` answer `422 take must be an integer number` when it is missing, while
  `GET /v1/quotations` applies its own default and returns 200. The client fills one in for the
  two that need it, so an empty `ListQuery` works everywhere. The ceiling is 50
  (`ListQuery::MAX_TAKE`): `take=51` is rejected with `422 take must not be greater than 50`,
  `take=0` is accepted and returns an empty page.
- **Endpoint access is per entitlement, not per key.** A sandbox key that quotes, books, tracks
  and prints happily can still get `403 Forbidden resource` from `GET /v1/invoices` alone. A 403
  from one endpoint therefore says nothing about the key as a whole.
- The order and quotation list rows are flattened compared to the booking response: `priceAmount`
  + `priceCurrency` instead of a `price` object, `runtimeDaysMin`/`Max` instead of `runtime`,
  `co2Emission*` instead of `co2Emission`. `Order::price()`, `->runtime()` and `->co2Emission()`
  rebuild the familiar objects.

---

## Schema drift

Small mismatches between the published schemas and live responses, all handled leniently:

- `platformTrackingUrl` is returned by `POST /v1/orders` (and documented on the tracking-link
  page) but is **absent from the `OrderProduct` schema**.
- The top-level `price` of a quotation carries `grossAmount` and `vatAmount`, and cost items carry
  `pricePartVat`, none of which appear in the `Price` / `CostItemProduct` schemas.
- The documented example response contains the cost item type `CLIMATE_COMPENSATION_SURCHARGE`,
  which is **not** in the `CostItemType` enum (the enum has `CLIMATE_NEUTRAL_SURCHARGE`).
  Unrecognised enum values parse to `null` here, with the raw string kept as
  `CostItem::$rawType`, so a new or undocumented type cannot break parsing.
- `CostItemSubtype` contains `DELIVERY_WHITOUT_CONSIGNEE_PRESENCE` - the typo is Cargoboard's and
  the value is kept verbatim.
- `LineOrder.unitPackageType` on responses uses a ~60-value internal code list (`BU`, `KN`, `RE`,
  ...) that is a superset of the ~19 codes accepted on a request, so `OrderLine::$unitPackageType`
  is a plain string with a `packageType()` helper for the known ones.
- `Quotation.quantityOfEuroPallets` is declared with type `Function` in the schema, which is a
  serialisation artefact rather than a real type; it is not modelled here.

---

## Dimensions, weights and what they are per

`unitLength` / `unitWidth` / `unitHeight` are **centimetres per unit** and `unitWeight` is
**kilograms per unit** - not totals for the line. A line with `unitQuantity: 3` and
`unitWeight: 100` is 300 kg. Getting this wrong is the most common cause of a price that looks
absurd in either direction.

Two special uses of the same fields:

- **Loading metres**: one `PARTIE` line, `unitLength` = LDM × 100 cm, `unitWidth` = 240 (the truck
  bed), `unitHeight` = 250/260 (tractor-trailer) or 300 (Megaliner), `unitWeight` = the whole
  block's gross weight.
- **Whole vehicles**: a `DIRECT_*` package type, where the "unit" is the vehicle. Everything but
  `DIRECT_TRUCK_40` must be booked with the `DIRECT` product; the 40-tonne truck runs as
  `STANDARD` or `EXPRESS`.

`isStackable` and `wantsPalletExchange` are optional but affect the price, so send them.

---

## Where the documentation lives

- Human documentation: <https://docs.cargoboard.com>
- Machine index of every page: <https://docs.cargoboard.com/llms.txt>
- Every reference page has a Markdown twin at `<page-url>.md`, which contains the full OpenAPI
  definition for that endpoint. A copy of all of them, extracted, is in
  [`plans/reference/openapi/`](../plans/reference/openapi) for offline reference.
- Tracking status codes (some 450 of them) are published as a Google Sheet linked from the
  [Track & Trace page](https://docs.cargoboard.com/reference/track-trace-shipment-status), not in
  the API reference. That is why `TrackingEvent::$code` is a string here rather than an enum:
  branch on the milestone (`TrackingStep`) for logic and use the code for display or logging.
- Questions: <api@cargoboard.com>. They answer quickly.
