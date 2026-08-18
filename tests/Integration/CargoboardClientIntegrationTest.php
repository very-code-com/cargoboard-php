<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\CargoboardConfig;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\LabelFormat;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardAdrSyncPendingException;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAuthException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Query\ListQuery;
use VeryCodeCom\Cargoboard\Tests\Support\ShipmentFactory;

/**
 * Integration tests: real calls against the Cargoboard sandbox (api-sandbox.cargoboard.com).
 *
 * Skipped unless CARGOBOARD_SANDBOX=1 and CARGOBOARD_API_KEY are set. Cargoboard issues
 * **separate keys per environment**, so a production key will simply be refused here with
 * HTTP 403; request a sandbox key from api@cargoboard.com.
 *
 * Usage:
 *   CARGOBOARD_SANDBOX=1 CARGOBOARD_API_KEY=xxx vendor/bin/phpunit --testsuite integration
 *
 * The sandbox is explicitly a place where "you cannot break anything": bookings there are not
 * executed and no truck is scheduled. Even so, the tests that place an order can be skipped
 * with CARGOBOARD_ALLOW_ORDERS=0 to keep a run purely read-only, and this suite must never be
 * pointed at a production key.
 */
final class CargoboardClientIntegrationTest extends TestCase
{
    private CargoboardClient $client;

    /** Reference of the order placed by this run, shared by the follow-up tests. */
    private static ?string $orderId = null;
    private static ?string $orderReference = null;

    protected function setUp(): void
    {
        if (getenv('CARGOBOARD_SANDBOX') !== '1') {
            $this->markTestSkipped('Set CARGOBOARD_SANDBOX=1 to run integration tests.');
        }

        $apiKey = getenv('CARGOBOARD_API_KEY') ?: '';

        if ($apiKey === '') {
            $this->markTestSkipped('Set CARGOBOARD_API_KEY to run integration tests.');
        }

        $this->client = new CargoboardClient(CargoboardConfig::sandbox($apiKey));
    }

    /**
     * Guard for the tests that place a real sandbox order. Harmless on the sandbox, never
     * acceptable against a production key, so it takes a deliberate opt-out to disable.
     */
    private function requireOrdersAllowed(): void
    {
        if (getenv('CARGOBOARD_ALLOW_ORDERS') === '0') {
            $this->markTestSkipped('CARGOBOARD_ALLOW_ORDERS=0: skipping the tests that place sandbox orders.');
        }
    }

    /** The next weekday, so the pickup date always satisfies the Monday-to-Friday rule. */
    private function nextWeekday(int $daysAhead = 2): string
    {
        $date = new \DateTimeImmutable("+{$daysAhead} weekdays");

        return $date->format('Y-m-d');
    }

    private function shipmentRequest(bool $forBooking = true): ShipmentRequest
    {
        return new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('40239', CountryCode::DE, 'Düsseldorf', $forBooking ? 'Examplestreet 12a' : null),
                name: $forBooking ? 'very-code-com/cargoboard-php integration test' : null,
                pickupOn: $this->nextWeekday(),
            ),
            consignee: new Consignee(
                address: new Address('41061', CountryCode::DE, 'Mönchengladbach', $forBooking ? 'Examplestreet 5' : null),
                name: $forBooking ? 'Integration Test Consignee' : null,
            ),
            lines: [
                new Line(
                    content: 'Integration test goods',
                    unitQuantity: 1,
                    unitPackageType: PackageType::EuroPallet,
                    unitLength: 120,
                    unitWidth: 80,
                    unitHeight: 100,
                    unitWeight: 150.0,
                    isStackable: true,
                ),
            ],
            customerOrderCode: 'CBPHP-' . date('YmdHis'),
        );
    }

    // -- quotations ---------------------------------------------------

    public function testQuoteReturnsABindingPrice(): string
    {
        $quotation = $this->client->quote($this->shipmentRequest(forBooking: false));

        self::assertNotSame('', $quotation->id);
        self::assertGreaterThan(0.0, $quotation->price->amount);
        self::assertSame('EUR', $quotation->price->currency);
        self::assertNotEmpty($quotation->costItems);
        self::assertNotNull($quotation->freightCost(), 'A quotation must contain a SHIPMENT cost item.');
        self::assertNotNull($quotation->delivery->latest);

        return $quotation->id;
    }

    public function testParcelModeIsPricedDifferentlyFromFreight(): void
    {
        // The single most expensive thing this client can get wrong: parcel mode is the
        // `x-transport-type-parcel-is-active` header, not a payload field, so an identical
        // request without it is quietly priced and booked as a freight shipment. This pins the
        // header end to end by checking that the very same payload comes back with two
        // different prices.
        $request = ShipmentFactory::parcel(2);

        $asParcel  = $this->client->withParcelMode()->quote($request);
        $asFreight = $this->client->quote($request);

        self::assertTrue($this->client->withParcelMode()->isParcelMode());
        self::assertFalse($this->client->isParcelMode());

        self::assertNotSame(
            $asFreight->price->amount,
            $asParcel->price->amount,
            'Parcel mode produced the freight price: the header is not reaching the API.',
        );
    }

    public function testQuotePricesEveryProductTheLaneSupports(): void
    {
        $base = $this->shipmentRequest(forBooking: false);
        $priced = 0;

        foreach ([Product::Standard, Product::Express] as $product) {
            try {
                $quotation = $this->client->quote($base->withProduct($product));
                self::assertGreaterThan(0.0, $quotation->price->amount);
                $priced++;
            } catch (CargoboardApiException $e) {
                // Not every product is offered on every lane; that is an answer, not a failure.
                self::assertContains($e->statusCode, [409, 422], 'Unexpected error: ' . $e->getMessage());
            }
        }

        self::assertGreaterThan(0, $priced, 'At least STANDARD should be priceable on a domestic lane.');
    }

    public function testListQuotationsReturnsAPage(): void
    {
        $page = $this->client->listQuotations(ListQuery::create()->take(5)->withTotal());

        self::assertLessThanOrEqual(5, count($page));

        if (!$page->isEmpty()) {
            self::assertNotSame('', $page->first()?->id);
        }
    }

    #[Depends('testQuoteReturnsABindingPrice')]
    public function testFetchQuotationById(string $quotationId): void
    {
        $quotation = $this->client->fetchQuotation($quotationId);

        self::assertSame($quotationId, $quotation->id);
    }

    // -- orders -------------------------------------------------------

    public function testPlaceOrderCreatesASandboxShipment(): void
    {
        $this->requireOrdersAllowed();

        $order = $this->client->placeOrder($this->shipmentRequest());

        self::assertNotSame('', $order->id);
        self::assertNotSame('', $order->reference, 'Cargoboard must return a shipment reference.');
        self::assertGreaterThan(0.0, $order->price->amount);

        self::$orderId = $order->id;
        self::$orderReference = $order->reference;
    }

    #[Depends('testPlaceOrderCreatesASandboxShipment')]
    public function testFetchOrderByCuidAndByReference(): void
    {
        $this->requireOrdersAllowed();

        if (self::$orderId === null || self::$orderReference === null) {
            self::markTestSkipped('No order was placed in this run.');
        }

        $byId = $this->client->fetchOrder(self::$orderId);
        self::assertSame(self::$orderId, $byId->id);

        // Cargoboard accepts either identifier wherever it takes an {id}.
        $byReference = $this->client->fetchOrder(self::$orderReference);
        self::assertSame(self::$orderReference, $byReference->reference);
    }

    #[Depends('testPlaceOrderCreatesASandboxShipment')]
    public function testFetchLabelsAndConfirmationReturnPdfs(): void
    {
        $this->requireOrdersAllowed();

        if (self::$orderId === null) {
            self::markTestSkipped('No order was placed in this run.');
        }

        foreach ([LabelFormat::A4, LabelFormat::A6] as $format) {
            $pdf = $this->client->fetchLabels(self::$orderId, $format);
            self::assertStringStartsWith('%PDF-', $pdf, "Labels in {$format->value} must be a PDF stream.");
        }

        $confirmation = $this->client->fetchConfirmation(self::$orderId);
        self::assertStringStartsWith('%PDF-', $confirmation);
    }

    #[Depends('testPlaceOrderCreatesASandboxShipment')]
    public function testFetchTrackingForAFreshOrder(): void
    {
        $this->requireOrdersAllowed();

        if (self::$orderReference === null) {
            self::markTestSkipped('No order was placed in this run.');
        }

        $tracking = $this->client->fetchTracking(self::$orderReference);

        // A brand-new order has milestones but usually no events yet.
        self::assertNotEmpty($tracking->steps);
        self::assertFalse($tracking->isDelivered());
    }

    #[Depends('testPlaceOrderCreatesASandboxShipment')]
    public function testCancelTheOrderPlacedByThisRun(): void
    {
        $this->requireOrdersAllowed();

        if (self::$orderId === null) {
            self::markTestSkipped('No order was placed in this run.');
        }

        // Leaving test bookings behind is impolite even on a sandbox.
        $result = $this->client->cancelOrder(self::$orderId);

        self::assertNotNull($result->status ?? $result->message);
    }

    public function testListOrdersReturnsAPage(): void
    {
        $page = $this->client->listOrders(ListQuery::create()->take(5)->withTotal());

        self::assertLessThanOrEqual(5, count($page));
    }

    // -- invoices and ADR ---------------------------------------------

    public function testListInvoicesReturnsAPage(): void
    {
        // Invoice access is a separate entitlement: a sandbox key that quotes and books happily
        // can still be refused here, which is an account setting rather than a client bug.
        try {
            $page = $this->client->listInvoices(ListQuery::create()->take(5));
        } catch (CargoboardAuthException) {
            self::markTestSkipped('This key is not entitled to /v1/invoices.');
        }

        self::assertLessThanOrEqual(5, count($page));
    }

    public function testListOrdersDefaultsTheMandatoryTakeParameter(): void
    {
        // /v1/orders answers 422 when `take` is missing, so an empty query must still work.
        $page = $this->client->listOrders();

        self::assertLessThanOrEqual(ListQuery::MAX_TAKE, count($page));
    }

    public function testFetchAdrDataForLithiumBatteries(): void
    {
        // The sandbox seeds its ADR table lazily: the first lookup for a UN number returns 202
        // and queues a sync, so this test can only assert the data once it is actually there.
        try {
            $adr = $this->client->fetchAdrData('3480');
        } catch (CargoboardAdrSyncPendingException $e) {
            self::assertSame('3480', $e->unNumber);
            self::assertSame(202, $e->statusCode);
            self::markTestSkipped('Sandbox is still synchronising ADR data for UN 3480.');
        }

        self::assertSame('3480', $adr->unNo);
        self::assertNotNull($adr->substanceName);

        // The lookup must produce a usable declaration without any local ADR table.
        $good = $adr->toDangerousGood(quantity: 1, weightGross: 11.0, weightNetOrVolume: 9.0, packageType: 'Kiste');
        self::assertSame('3480', $good->unNo);
        self::assertNotSame('', $good->substanceName);
    }

    public function testAnUnknownUnNumberIsReportedAsNotFound(): void
    {
        // Same caveat as above: until the sync has run, even a bogus UN number is answered with
        // 202 rather than 404, because the API cannot yet tell "unknown" from "not fetched".
        try {
            $this->client->fetchAdrData('9999');
        } catch (CargoboardAdrSyncPendingException) {
            self::markTestSkipped('Sandbox is still synchronising ADR data for UN 9999.');
        } catch (CargoboardNotFoundException) {
            self::assertTrue(true);
            return;
        }

        self::fail('Expected an unknown UN number to be rejected.');
    }

    public function testAMalformedUnNumberIsRejectedByTheApi(): void
    {
        // Server-side rule, worth pinning: the path segment must be 1-4 digits.
        $this->expectException(CargoboardUnprocessableEntityException::class);

        $this->client->fetchAdrData('9999999');
    }

    // -- authentication ------------------------------------------------

    public function testAnInvalidApiKeyIsRejected(): void
    {
        $client = new CargoboardClient(CargoboardConfig::sandbox('definitely-not-a-valid-key'));

        try {
            $client->listOrders(ListQuery::create()->take(1));
            self::fail('Expected CargoboardAuthException.');
        } catch (CargoboardAuthException $e) {
            self::assertContains($e->statusCode, [401, 403]);
        }
    }
}
