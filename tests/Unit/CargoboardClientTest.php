<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\CargoboardConfig;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\TrackingEvent;
use VeryCodeCom\Cargoboard\Dto\TrackingResult;
use VeryCodeCom\Cargoboard\Enum\CostItemType;
use VeryCodeCom\Cargoboard\Enum\LabelFormat;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Enum\ShipmentStatus;
use VeryCodeCom\Cargoboard\Enum\TrackingStepType;
use VeryCodeCom\Cargoboard\Enum\TransportType;
use VeryCodeCom\Cargoboard\Exception\CargoboardAdrSyncPendingException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAuthException;
use VeryCodeCom\Cargoboard\Exception\CargoboardConflictException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;
use VeryCodeCom\Cargoboard\Exception\CargoboardRateLimitException;
use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Exception\CargoboardServerException;
use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Exception\CargoboardValidationException;
use VeryCodeCom\Cargoboard\Query\ListQuery;
use VeryCodeCom\Cargoboard\Tests\Support\FakeTransport;
use VeryCodeCom\Cargoboard\Tests\Support\ShipmentFactory;
use VeryCodeCom\Cargoboard\Transport\TransportResponse;

/**
 * Exercises CargoboardClient's orchestration (validate -> build request -> transport -> parse ->
 * classify errors) against a scripted transport, with no real network calls.
 */
final class CargoboardClientTest extends TestCase
{
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/' . $name);
        self::assertIsString($contents, "Fixture {$name} could not be read.");

        return $contents;
    }

    private function client(FakeTransport $transport, bool $parcelMode = false): CargoboardClient
    {
        return new CargoboardClient(
            new CargoboardConfig('test-api-key', sandbox: true, parcelMode: $parcelMode),
            $transport,
        );
    }

    // -- quotations ---------------------------------------------------

    public function testQuoteParsesPriceRuntimeAndBreakdown(): void
    {
        $transport = FakeTransport::json($this->fixture('quotation_success.json'), 201);
        $result = $this->client($transport)->quote(ShipmentFactory::quotable());

        self::assertSame('cm33264tt04i0qt0dluradaf6', $result->id);
        self::assertSame(Product::Standard, $result->product);
        self::assertSame(TransportType::Groupage, $result->transportType);
        self::assertSame(90.85, $result->price->amount);
        self::assertSame('EUR', $result->price->currency);
        self::assertSame(108.11, $result->price->grossAmount);
        self::assertSame(1.0, $result->runtime->daysMin);
        self::assertSame(2.0, $result->runtime->daysMax);
        self::assertSame('2024-12-12', $result->delivery->earliest?->format('Y-m-d'));
        self::assertSame(33.88, $result->co2Emission->value);
        self::assertCount(2, $result->costItems);
        self::assertSame('Frachtkosten', $result->freightCost()?->description);
        self::assertSame(19.0, $result->costItems[0]->pricePartVat?->percentage);
    }

    public function testQuotePostsToTheQuotationsEndpointWithTheApiKey(): void
    {
        $transport = FakeTransport::json($this->fixture('quotation_success.json'), 201);
        $this->client($transport)->quote(ShipmentFactory::quotable());

        $request = $transport->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->method);
        self::assertSame('https://api-sandbox.cargoboard.com/v1/quotations', $request->url);
        self::assertSame('test-api-key', $request->headers['X-API-KEY']);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertArrayNotHasKey('x-transport-type-parcel-is-active', $request->headers);

        $body = $transport->lastBody();
        self::assertSame('STANDARD', $body['product']);
        self::assertSame('10115', $body['shipper']['address']['postCode']);
        self::assertSame('FP', $body['lines'][0]['unitPackageType']);
    }

    public function testQuoteOnlyNeedsQuotationLevelAddressFields(): void
    {
        // The same request would fail booking validation: no names, no streets.
        $transport = FakeTransport::json($this->fixture('quotation_success.json'), 201);
        $this->client($transport)->quote(ShipmentFactory::quotable());

        self::assertCount(1, $transport->requests);
    }

    public function testUnknownCostItemTypeIsPreservedAsRawString(): void
    {
        // The documented example response carries CLIMATE_COMPENSATION_SURCHARGE, which is not
        // in the schema's enum; it must not break parsing.
        $transport = FakeTransport::json($this->fixture('quotation_success.json'), 201);
        $result = $this->client($transport)->quote(ShipmentFactory::quotable());

        self::assertNull($result->costItems[1]->type);
        self::assertSame('CLIMATE_COMPENSATION_SURCHARGE', $result->costItems[1]->rawType);
    }

    public function testListQuotationsParsesPageAndTotal(): void
    {
        $transport = FakeTransport::json($this->fixture('quotations_list.json'));
        $page = $this->client($transport)->listQuotations(ListQuery::create()->take(1)->withTotal());

        self::assertCount(1, $page);
        self::assertSame(1.0, $page->total);
        self::assertTrue($page->first()?->isBooked());
        self::assertSame(2200.0, $page->nextCursor());
        self::assertStringContainsString('take=1', $transport->lastRequest()?->url ?? '');
        self::assertStringContainsString('total=true', $transport->lastRequest()?->url ?? '');
    }

    public function testFetchQuotationAcceptsABareResourceWithoutTheDataEnvelope(): void
    {
        $bare = json_encode(['id' => 'cm-bare', 'product' => 'EXPRESS', 'priceAmount' => 120.5]);
        self::assertIsString($bare);

        $quotation = $this->client(FakeTransport::json($bare))->fetchQuotation('cm-bare');

        self::assertSame('cm-bare', $quotation->id);
        self::assertSame(Product::Express, $quotation->product);
        self::assertSame(120.5, $quotation->price()?->amount);
    }

    public function testBookQuotationPostsToTheBookEndpoint(): void
    {
        $transport = FakeTransport::json($this->fixture('order_success.json'), 201);
        $order = $this->client($transport)->bookQuotation('cm33264tt04i0qt0dluradaf6', ShipmentFactory::bookable());

        self::assertSame(
            'https://api-sandbox.cargoboard.com/v1/quotations/cm33264tt04i0qt0dluradaf6/book',
            $transport->lastRequest()?->url,
        );
        self::assertSame('10374504', $order->reference);
    }

    // -- orders -------------------------------------------------------

    public function testPlaceOrderParsesBothIdentifiers(): void
    {
        $transport = FakeTransport::json($this->fixture('order_success.json'), 201);
        $order = $this->client($transport)->placeOrder(ShipmentFactory::bookable());

        self::assertSame('cm3328igs04jbqt0dluurnxyl', $order->id);
        self::assertSame('10374504', $order->reference);
        self::assertSame(94.93, $order->price->amount);
        self::assertNotNull($order->platformTrackingUrl);
        self::assertStringContainsString('order-id=cm3328igs04jbqt0dluurnxyl', $order->platformTrackingUrl);
        self::assertSame(
            'https://api-sandbox.cargoboard.com/v1/orders/10374504/tracking',
            $order->trackingApiUrl(),
        );
        self::assertCount(1, $order->costItemsOfType(CostItemType::ClimateNeutralSurcharge));
    }

    public function testListOrdersSendsTheMandatoryTakeParameterWhenNoQueryIsGiven(): void
    {
        // /v1/orders answers 422 "take must be an integer number" without it, unlike
        // /v1/quotations which applies its own default.
        $transport = FakeTransport::json('{"data":[],"links":[]}');
        $this->client($transport)->listOrders();

        self::assertStringContainsString('take=50', $transport->lastRequest()?->url ?? '');
    }

    public function testListOrdersKeepsAnExplicitTake(): void
    {
        $transport = FakeTransport::json('{"data":[],"links":[]}');
        $this->client($transport)->listOrders(ListQuery::create()->take(5));

        self::assertStringContainsString('take=5', $transport->lastRequest()?->url ?? '');
        self::assertStringNotContainsString('take=50', $transport->lastRequest()?->url ?? '');
    }

    public function testListOrdersParsesNestedLinesBarcodesAndInvoices(): void
    {
        $transport = FakeTransport::json($this->fixture('orders_list.json'));
        $page = $this->client($transport)->listOrders();

        self::assertCount(1, $page);
        self::assertSame(137.0, $page->total);

        $order = $page->first();
        self::assertNotNull($order);
        self::assertSame(ShipmentStatus::Transit, $order->shipmentStatus);
        self::assertSame('Mustermann GmbH', $order->shipper->name);
        self::assertTrue($order->consignee->isPrivateCustomer);
        self::assertSame(['103745040001', '103745040002'], $order->barcodes());
        self::assertSame(200.0, $order->totalWeightKg());
        self::assertCount(2, $order->costItems);
        self::assertSame('RE-2024-0815', $order->easybillInvoices[0]->documentNumber);
        self::assertFalse($order->needsConfirmation());
    }

    public function testFetchOrderAcceptsAReferenceAsTheIdentifier(): void
    {
        $transport = FakeTransport::json($this->fixture('orders_list.json'));
        // The list fixture is reused: fetchOrder tolerates an envelope whose data is a list only
        // in as much as it falls back to the whole body, so assert on the URL rather than on the DTO.
        $this->client($transport)->fetchOrder('10374504');

        self::assertSame('https://api-sandbox.cargoboard.com/v1/orders/10374504', $transport->lastRequest()?->url);
    }

    public function testCancelOrderPostsWithoutABody(): void
    {
        $transport = FakeTransport::json($this->fixture('cancel_success.json'), 201);
        $result = $this->client($transport)->cancelOrder('cm3328igs04jbqt0dluurnxyl');

        self::assertSame('CANCELLED', $result->status);
        self::assertSame('POST', $transport->lastRequest()?->method);
        self::assertSame('', $transport->lastRequest()?->body);
        self::assertStringEndsWith('/orders/cm3328igs04jbqt0dluurnxyl/cancel', $transport->lastRequest()?->url ?? '');
    }

    // -- documents ----------------------------------------------------

    public function testFetchLabelsReturnsRawPdfBytesAndPassesTheFormat(): void
    {
        $transport = FakeTransport::pdf("%PDF-1.7\nlabels");
        $pdf = $this->client($transport)->fetchLabels('10374504', LabelFormat::A6);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/orders/10374504/print-shipment-labels?format=A6', $transport->lastRequest()?->url ?? '');
    }

    public function testFetchConfirmationReturnsRawPdfBytes(): void
    {
        $transport = FakeTransport::pdf();
        $pdf = $this->client($transport)->fetchConfirmation('10374504');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringEndsWith('/orders/10374504/print-confirmation', $transport->lastRequest()?->url ?? '');
    }

    public function testPdfEndpointRejectsANonPdfSuccessBody(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, '{"data":null}', ['content-type' => 'application/json'])]);

        $this->expectException(CargoboardResponseParseException::class);
        $this->expectExceptionMessage('expected a PDF stream');

        $this->client($transport)->fetchLabels('10374504');
    }

    public function testPdfEndpointMapsAJsonErrorBodyToAnApiException(): void
    {
        $transport = new FakeTransport([
            new TransportResponse(404, $this->fixture('error_403_forbidden.json'), ['content-type' => 'application/json']),
        ]);

        $this->expectException(CargoboardNotFoundException::class);

        $this->client($transport)->fetchLabels('does-not-exist');
    }

    // -- tracking -----------------------------------------------------

    public function testFetchTrackingParsesStepsAndEvents(): void
    {
        $transport = FakeTransport::json($this->fixture('tracking_success.json'));
        $tracking = $this->client($transport)->fetchTracking('10374504');

        self::assertCount(7, $tracking->steps);
        self::assertCount(2, $tracking->events);
        self::assertSame(TrackingStepType::Unloaded, $tracking->currentStep()?->type);
        self::assertTrue($tracking->hasWarning());
        self::assertFalse($tracking->isDelivered());
        self::assertSame('Depot Halver', $tracking->events[0]->location?->name);
        self::assertTrue($tracking->events[1]->hasVehiclePosition());

        $estimate = $tracking->estimatedDelivery();
        self::assertNotNull($estimate);
        self::assertSame('2024-12-13 10:00', $estimate['from']?->format('Y-m-d H:i'));
    }

    public function testDeliveredTrackingExposesTheSigner(): void
    {
        $transport = FakeTransport::json($this->fixture('tracking_delivered.json'));
        $tracking = $this->client($transport)->fetchTracking('10374504');

        self::assertTrue($tracking->isDelivered());
        self::assertSame('F. Müller', $tracking->signedBy());
        self::assertTrue($tracking->latestEvent()?->isProofOfDelivery());
    }

    /**
     * The three findings reported by an integrator against live order 12198331 (Aug 2026),
     * pinned against a fixture with that response's shape.
     */
    public function testLiveTrackingFeedKeepsEventIdsAndEveryNotificationEvent(): void
    {
        $transport = FakeTransport::json($this->fixture('tracking_live_events.json'));
        $tracking  = $this->client($transport)->fetchTracking('12198331');

        // 1. The id the API sends on every event survives parsing.
        self::assertSame('cmsy9hj2u1y523p0y5uawdp9y', $tracking->events[0]->id);

        // The three 722 notification events share (code, originatedAt) - two of them share the
        // message too - so anything but the id as a key loses one of them silently.
        $notifications = array_filter($tracking->timeline(), static fn ($e): bool => $e->code === '722');
        self::assertCount(3, $notifications);

        $ids = array_map(static fn ($e): ?string => $e->id, $tracking->timeline());
        self::assertSame($ids, array_unique($ids), 'Every live event carries a distinct id.');
    }

    public function testTimelineIsChronologicalAndDeduplicatedWhileEventsStayRaw(): void
    {
        $transport = FakeTransport::json($this->fixture('tracking_live_events.json'));
        $tracking  = $this->client($transport)->fetchTracking('12198331');

        $rawCodes = array_map(static fn ($e): ?string => $e->code, $tracking->events);
        self::assertSame(['540', '510', '722', '722', '722', '831', '809'], $rawCodes);

        // 809 originated before 831 but the API sent it last; timeline() puts it back in order
        // without touching the raw feed.
        $orderedCodes = array_map(static fn ($e): ?string => $e->code, $tracking->timeline());
        self::assertSame(['540', '510', '722', '722', '722', '809', '831'], $orderedCodes);
    }

    public function testTimelineDropsAnEventTheFeedRepeats(): void
    {
        $repeated = ['id' => 'evt-1', 'code' => '540', 'originatedAt' => '2026-08-18T06:06:20.000Z'];
        $tracking = TrackingResult::fromArray(['statusEventHistory' => [$repeated, $repeated]]);

        self::assertCount(2, $tracking->events);
        self::assertCount(1, $tracking->timeline());
    }

    /**
     * Without an id there is nothing left but the payload itself, and two events that differ
     * only in a field the API did not send collapse. This is what deduplicating on
     * `(code, originatedAt)` does to the 722 events, one level worse.
     */
    public function testFingerprintFallsBackToThePayloadWhenTheApiSendsNoId(): void
    {
        $withoutId = new TrackingEvent(code: '722', originatedAt: new \DateTimeImmutable('2026-08-18T16:00:00Z'), message: 'V +49761584746');
        $other     = new TrackingEvent(code: '722', originatedAt: new \DateTimeImmutable('2026-08-18T16:00:00Z'), message: 'E info@example.com');

        self::assertNotSame($withoutId->fingerprint(), $other->fingerprint());
        self::assertSame($withoutId->fingerprint(), (clone $withoutId)->fingerprint());
    }

    public function testDescribeFallsBackToTheEstimatesAndNeverToABareCode(): void
    {
        $transport = FakeTransport::json($this->fixture('tracking_live_events.json'));
        $timeline  = $this->client($transport)->fetchTracking('12198331')->timeline();

        $byCode = [];
        foreach ($timeline as $event) {
            $byCode[$event->code ?? ''] ??= $event;
        }

        // 540 has message: null but carries both windows - the case that used to render "540".
        self::assertSame(
            'Estimates updated: collection 18.08.2026 07:00-15:00, delivery 19.08.2026 06:00 - 21.08.2026 14:00',
            $byCode['540']->describe(),
        );

        // A message is used as-is; the estimates stay available separately for anyone who
        // wants both halves on one line.
        self::assertSame('BO26082222', $byCode['510']->describe());
        self::assertSame('Delivery estimated 19.08.2026 06:00 - 20.08.2026 14:00', $byCode['510']->estimateSummary());

        self::assertSame('The shipment has arrived at the delivery depot', $byCode['831']->describe());

        // A proof of delivery arrives as a bare code plus a signature - reading the signature
        // is not the same as guessing what the code means.
        self::assertSame(
            'Signed by A. Nowak',
            (new TrackingEvent(code: '700', nameOfSigner: 'A. Nowak'))->describe(),
        );

        // An event with neither text, signature nor estimates says so honestly, without
        // inventing a meaning for the number.
        self::assertSame('Status 900', (new TrackingEvent(code: '900'))->describe());
        self::assertSame('Status update', (new TrackingEvent())->describe());
    }

    public function testWindowsReadBothTheDocumentedAndTheLiveFieldSpelling(): void
    {
        $live = new TrackingEvent(
            estimatedPickupAtFrom: new \DateTimeImmutable('2026-08-18T07:00:00Z'),
            estimatedPickupAtUntil: new \DateTimeImmutable('2026-08-18T15:00:00Z'),
        );
        self::assertSame('18.08.2026 07:00-15:00', $live->pickupWindow()?->format());
        self::assertTrue($live->hasEstimates());

        // What the OpenAPI definition documents instead, as the older fixtures still send.
        $documented = new TrackingEvent(
            estimatedCollectionAtFrom: new \DateTimeImmutable('2026-08-18T07:00:00Z'),
            estimatedArrivalAtFrom: new \DateTimeImmutable('2026-08-19T06:00:00Z'),
        );
        self::assertSame('from 18.08.2026 07:00', $documented->pickupWindow()?->format());
        self::assertSame('from 19.08.2026 06:00', $documented->deliveryWindow()?->format());

        self::assertNull((new TrackingEvent(code: '540'))->pickupWindow());
        self::assertFalse((new TrackingEvent(code: '540'))->hasEstimates());
    }

    public function testLatestWindowsTakeTheNewestEstimateAndAcceptADisplayTimezone(): void
    {
        $transport = FakeTransport::json($this->fixture('tracking_live_events.json'));
        $tracking  = $this->client($transport)->fetchTracking('12198331');

        // 809 is the newest event carrying a delivery window, even though the API sent it last.
        self::assertSame(
            '21.08.2026 05:00-12:00',
            $tracking->latestDeliveryWindow()?->format(),
        );
        self::assertSame('18.08.2026 07:00-15:00', $tracking->latestPickupWindow()?->format());

        // The 1.0 array shape returns the same estimate.
        self::assertSame(
            '2026-08-21 05:00',
            $tracking->estimatedDelivery()['from']?->format('Y-m-d H:i'),
        );

        self::assertSame(
            '21.08.2026 07:00-14:00',
            $tracking->latestDeliveryWindow()?->format(new \DateTimeZone('Europe/Berlin')),
        );
    }

    public function testAPrivateConsigneeWithoutADeliveryArrangementIsWarnedAboutButNotRefused(): void
    {
        $base   = ShipmentFactory::bookable();
        $client = $this->client(FakeTransport::json($this->fixture('order_success.json')));

        $private = static fn (Consignee $consignee): ShipmentRequest => new ShipmentRequest(
            product: $base->product,
            shipper: $base->shipper,
            consignee: $consignee,
            lines: $base->lines,
        );

        $unarranged = $private(new Consignee(
            address: $base->consignee->address,
            name: $base->consignee->name,
            isPrivateCustomer: true,
        ));

        $warnings = $client->warningsFor($unarranged);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('wantsContactBeforeDelivery', $warnings[0]);

        // A warning is not an error: Cargoboard accepts the booking, so this library does too.
        self::assertSame([], $client->validateLocally($unarranged));

        $arranged = $private(new Consignee(
            address: $base->consignee->address,
            name: $base->consignee->name,
            isPrivateCustomer: true,
            wantsContactBeforeDelivery: true,
        ));
        self::assertSame([], $client->warningsFor($arranged));

        // A business consignee is never flagged.
        self::assertSame([], $client->warningsFor($base));
    }

    // -- invoices and ADR ---------------------------------------------

    public function testListInvoicesAlwaysSendsTakeBecauseTheEndpointRequiresIt(): void
    {
        $transport = FakeTransport::json($this->fixture('invoices_list.json'));
        $page = $this->client($transport)->listInvoices();

        self::assertStringContainsString('take=50', $transport->lastRequest()?->url ?? '');
        self::assertCount(2, $page);
        self::assertSame('RE-2024-0815', $page->first()?->documentNumber);
        self::assertTrue($page->invoices[1]->isCancelled());
        self::assertCount(1, $page->overdue(new \DateTimeImmutable('2025-01-01')));
    }

    public function testFetchInvoicePdfUsesTheDocumentId(): void
    {
        $transport = FakeTransport::pdf();
        $this->client($transport)->fetchInvoicePdf('12345');

        self::assertStringEndsWith('/invoices/12345/pdf', $transport->lastRequest()?->url ?? '');
    }

    public function testFetchAdrDataRejectsTheSyncQueued202InsteadOfReturningAnEmptyRecord(): void
    {
        // Live behaviour absent from the schema: a UN number the API has not cached yet comes
        // back as 202 with no ADR fields. 202 is a success status, so the lenient DTO parsing
        // would happily produce an all-null AdrData and a caller would declare a dangerous good
        // with no hazard class at all.
        $transport = FakeTransport::json(
            '{"statusCode":202,"code":"DANGEROUS_GOODS_UN_NUMBER_SYNC_QUEUED",'
            . '"message":"Dangerous goods data for UN number 3480 is being synchronized. Retry later."}',
            202,
        );

        try {
            $this->client($transport)->fetchAdrData('3480');
            self::fail('Expected a 202 sync-queued response to be reported.');
        } catch (CargoboardAdrSyncPendingException $e) {
            self::assertSame('3480', $e->unNumber);
            self::assertSame(202, $e->statusCode);
            self::assertStringContainsString('synchronized', $e->getMessage());
            self::assertSame([CargoboardAdrSyncPendingException::CODE], $e->errors);
        }
    }

    public function testFetchAdrDataStillAcceptsAPlain200(): void
    {
        $transport = FakeTransport::json($this->fixture('adr_3480_schema_shape.json'), 200);

        self::assertSame('3480', $this->client($transport)->fetchAdrData('3480')->unNo);
    }

    public function testFetchAdrDataParsesTheSchemaShape(): void
    {
        $transport = FakeTransport::json($this->fixture('adr_3480_schema_shape.json'));
        $adr = $this->client($transport)->fetchAdrData('3480');

        self::assertSame('3480', $adr->unNo);
        self::assertSame('LITHIUM-IONEN-BATTERIEN', $adr->substanceName);
        self::assertSame('9A', $adr->riskMain);
        self::assertSame(3, $adr->pointsMultiplier);
        self::assertTrue($adr->isLimitedQuantityEligible);
        self::assertStringEndsWith('/dangerous-goods/un-numbers/3480', $transport->lastRequest()?->url ?? '');
    }

    public function testFetchAdrDataAlsoParsesTheDocumentedExampleShape(): void
    {
        $transport = FakeTransport::json($this->fixture('adr_3480_example_shape.json'));
        $adr = $this->client($transport)->fetchAdrData('3480');

        self::assertSame('3480', $adr->unNo);
        self::assertSame('LITHIUM-IONEN-BATTERIEN', $adr->substanceName);
        self::assertSame('9A', $adr->riskMain);
        self::assertSame('(E)', $adr->tunnelRestriction);
        self::assertTrue($adr->isLimitedQuantityEligible);
        self::assertTrue($adr->hasSpecialProvision188());
        self::assertContains('P903', $adr->packagingInstructionList());
    }

    // -- parcel mode --------------------------------------------------

    public function testParcelModeAddsTheActivationHeader(): void
    {
        $transport = FakeTransport::json($this->fixture('order_success.json'), 201);
        $this->client($transport)->withParcelMode()->placeOrder(ShipmentFactory::parcel());

        self::assertSame('true', $transport->lastRequest()?->headers['x-transport-type-parcel-is-active'] ?? null);
    }

    public function testWithParcelModeLeavesTheOriginalClientUntouched(): void
    {
        $client = $this->client(new FakeTransport());

        self::assertFalse($client->isParcelMode());
        self::assertTrue($client->withParcelMode()->isParcelMode());
        self::assertFalse($client->isParcelMode());
    }

    public function testParcelModeAppliesTheParcelSizeRules(): void
    {
        $client = $this->client(new FakeTransport(), parcelMode: true);

        $this->expectException(CargoboardValidationException::class);
        $this->expectExceptionMessage('exceeds the 32 kg parcel limit');

        // A euro pallet is fine as freight and far too big as a parcel.
        $client->placeOrder(ShipmentFactory::bookable());
    }

    // -- error classification -----------------------------------------

    public function testValidationFailsBeforeAnyRequestIsSent(): void
    {
        $transport = new FakeTransport();

        try {
            // quotable() has no names or streets, so it cannot be booked.
            $this->client($transport)->placeOrder(ShipmentFactory::quotable());
            self::fail('Expected CargoboardValidationException.');
        } catch (CargoboardValidationException $e) {
            self::assertContains('shipper.name is required for a booking (optional for a quotation).', $e->errors);
            self::assertSame([], $transport->requests, 'No request must be sent when local validation fails.');
        }
    }

    public function testForbiddenIsReportedAsAnAuthExceptionWithAnActionableHint(): void
    {
        $transport = new FakeTransport([new TransportResponse(403, $this->fixture('error_403_forbidden.json'))]);

        try {
            $this->client($transport)->quote(ShipmentFactory::quotable());
            self::fail('Expected CargoboardAuthException.');
        } catch (CargoboardAuthException $e) {
            self::assertSame(403, $e->statusCode);
            self::assertFalse($e->isMissingCredentials());
            self::assertStringContainsString('sandbox and production keys are not interchangeable', $e->getMessage());
        }
    }

    public function testMissingKeyIsReportedAsA401AuthException(): void
    {
        $transport = new FakeTransport([new TransportResponse(401, '{"statusCode":401,"message":"Unauthorized","error":"Unauthorized"}')]);

        try {
            $this->client($transport)->listOrders();
            self::fail('Expected CargoboardAuthException.');
        } catch (CargoboardAuthException $e) {
            self::assertTrue($e->isMissingCredentials());
        }
    }

    public function testUnprocessableEntityCarriesPerFieldMessages(): void
    {
        $transport = new FakeTransport([new TransportResponse(422, $this->fixture('error_422_country.json'))]);

        try {
            $this->client($transport)->quote(ShipmentFactory::quotable());
            self::fail('Expected CargoboardUnprocessableEntityException.');
        } catch (CargoboardUnprocessableEntityException $e) {
            self::assertCount(2, $e->errors);
            self::assertTrue($e->hasError('countryCode'));
            self::assertSame(['shipper.address.countryCode', 'lines.0.unitWeight'], $e->getFieldNames());
        }
    }

    public function testConflictIsReportedAsAConflictException(): void
    {
        $transport = new FakeTransport([new TransportResponse(409, $this->fixture('error_409_conflict.json'))]);

        $this->expectException(CargoboardConflictException::class);
        $this->expectExceptionMessage('current state');

        $this->client($transport)->cancelOrder('10374504');
    }

    public function testServerErrorsAreClassifiedAndFlaggedForRetry(): void
    {
        $bad = new FakeTransport([new TransportResponse(502, '<html><body>Bad Gateway</body></html>')]);

        try {
            $this->client($bad)->listOrders();
            self::fail('Expected CargoboardServerException.');
        } catch (CargoboardServerException $e) {
            self::assertTrue($e->isRetryable());
            self::assertStringContainsString('Bad Gateway', $e->getMessage());
        }

        $internal = new FakeTransport([new TransportResponse(500, '{"statusCode":500,"message":"Internal Server Error"}')]);

        try {
            $this->client($internal)->listOrders();
            self::fail('Expected CargoboardServerException.');
        } catch (CargoboardServerException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    public function testRateLimitCarriesTheRetryAfterHeader(): void
    {
        $transport = new FakeTransport([new TransportResponse(429, '{"message":"Too Many Requests"}', ['retry-after' => '120'])]);

        try {
            $this->client($transport)->listOrders();
            self::fail('Expected CargoboardRateLimitException.');
        } catch (CargoboardRateLimitException $e) {
            self::assertSame(120, $e->retryAfterSeconds());
        }
    }

    public function testMalformedJsonBecomesAParseException(): void
    {
        $transport = FakeTransport::json('{not json');

        $this->expectException(CargoboardResponseParseException::class);

        $this->client($transport)->listOrders();
    }

    public function testMissingDataEnvelopeBecomesAParseException(): void
    {
        $transport = FakeTransport::json('{"links":[]}', 201);

        $this->expectException(CargoboardResponseParseException::class);
        $this->expectExceptionMessage('has no "data" object');

        $this->client($transport)->quote(ShipmentFactory::quotable());
    }

    public function testTransportErrorsBubbleUpUnchanged(): void
    {
        $transport = new FakeTransport([new CargoboardTransportException('cURL error #28: timeout')]);

        $this->expectException(CargoboardTransportException::class);

        $this->client($transport)->listOrders();
    }

    // -- debug mode ---------------------------------------------------

    public function testDebugModeAttachesTheRawResponseToTheException(): void
    {
        $transport = new FakeTransport([new TransportResponse(422, $this->fixture('error_422_country.json'))]);
        $client = new CargoboardClient(
            new CargoboardConfig('test-api-key', sandbox: true, debug: true),
            $transport,
        );

        try {
            $client->quote(ShipmentFactory::quotable());
            self::fail('Expected CargoboardUnprocessableEntityException.');
        } catch (CargoboardUnprocessableEntityException $e) {
            self::assertNotNull($e->getRawResponse());
            self::assertStringContainsString('countryCode', (string) $e->getRawResponse());
            self::assertStringContainsString('--- Raw Cargoboard response ---', $e->getDebugReport());
        }
    }

    public function testRawResponseIsNotAttachedWhenDebugIsOff(): void
    {
        $transport = new FakeTransport([new TransportResponse(422, $this->fixture('error_422_country.json'))]);

        try {
            $this->client($transport)->quote(ShipmentFactory::quotable());
            self::fail('Expected CargoboardUnprocessableEntityException.');
        } catch (CargoboardUnprocessableEntityException $e) {
            self::assertNull($e->getRawResponse());
        }
    }

    // -- named constructors -------------------------------------------

    public function testNamedConstructorsPickTheRightEnvironment(): void
    {
        self::assertSame('sandbox', CargoboardClient::sandbox('k')->config()->getEnvironment());
        self::assertSame('production', CargoboardClient::production('k')->config()->getEnvironment());
    }
}
