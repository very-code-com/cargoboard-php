<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Internal\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\DangerousGood;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\NeutralData;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator;
use VeryCodeCom\Cargoboard\Tests\Support\ShipmentFactory;
use VeryCodeCom\Cargoboard\Validation\ValidationMode;

/**
 * Covers every documented business rule the validator enforces, so that a change to Cargoboard's
 * rules shows up here rather than as a 422 in production.
 */
final class ShipmentValidatorTest extends TestCase
{
    private ShipmentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ShipmentValidator();
    }

    /** @return list<string> */
    private function validate(ShipmentRequest $request, ValidationMode $mode = ValidationMode::Order, bool $parcel = false): array
    {
        return $this->validator->validate($request, $mode, $parcel);
    }

    private function assertHasError(string $needle, ShipmentRequest $request, ValidationMode $mode = ValidationMode::Order, bool $parcel = false): void
    {
        $errors = $this->validate($request, $mode, $parcel);

        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                self::assertTrue(true);
                return;
            }
        }

        self::fail(sprintf("No error containing \"%s\".\nGot: %s", $needle, $errors === [] ? '(none)' : implode("\n     ", $errors)));
    }

    // -- happy paths --------------------------------------------------

    public function testACompleteBookingIsValid(): void
    {
        self::assertSame([], $this->validate(ShipmentFactory::bookable()));
    }

    public function testAMinimalQuotationIsValid(): void
    {
        self::assertSame([], $this->validate(ShipmentFactory::quotable(), ValidationMode::Quotation));
    }

    public function testAValidParcelShipmentPassesTheParcelRules(): void
    {
        self::assertSame([], $this->validate(ShipmentFactory::parcel(), ValidationMode::Order, parcel: true));
    }

    // -- address rules ------------------------------------------------

    public function testQuotationRulesDoNotRequireNamesOrStreets(): void
    {
        self::assertSame([], $this->validate(ShipmentFactory::quotable(), ValidationMode::Quotation));
    }

    public function testBookingRequiresNamesStreetsCitiesAndAPickupDate(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(address: new Address('10115', CountryCode::DE)),
            consignee: new Consignee(address: new Address('33100', CountryCode::DE)),
            lines: [ShipmentFactory::palletLine()],
        );

        $errors = $this->validate($request);

        self::assertContains('shipper.name is required for a booking (optional for a quotation).', $errors);
        self::assertContains('shipper.address.street is required for a booking (optional for a quotation).', $errors);
        self::assertContains('shipper.address.city is required for a booking.', $errors);
        self::assertContains('consignee.name is required for a booking (optional for a quotation).', $errors);
        self::assertContains('consignee.address.street is required for a booking (optional for a quotation).', $errors);
        self::assertContains('shipper.pickupOn (or pickupAtFrom + pickupAtUntil) is required for a booking.', $errors);
    }

    public function testAPickupWindowSatisfiesThePickupDateRequirement(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupAtFrom: ShipmentFactory::PICKUP_DATE . 'T08:00:00Z',
                pickupAtUntil: ShipmentFactory::PICKUP_DATE . 'T14:00:00Z',
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: [ShipmentFactory::palletLine()],
        );

        self::assertSame([], $this->validate($request));
    }

    // -- date rules ---------------------------------------------------

    public function testWeekendPickupIsRejected(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupOn: ShipmentFactory::WEEKEND_DATE,
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: [ShipmentFactory::palletLine()],
        );

        $this->assertHasError('shipper.pickupOn must be a weekday', $request);
    }

    public function testPickupWindowMustFallOnTheSameDayAsPickupOn(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupOn: ShipmentFactory::PICKUP_DATE,
                pickupAtFrom: '2026-08-19T08:00:00Z',
                pickupAtUntil: '2026-08-19T14:00:00Z',
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: [ShipmentFactory::palletLine()],
        );

        $this->assertHasError('must fall on the same day as shipper.pickupOn', $request);
    }

    public function testAnInvertedPickupWindowIsRejected(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupAtFrom: ShipmentFactory::PICKUP_DATE . 'T16:00:00Z',
                pickupAtUntil: ShipmentFactory::PICKUP_DATE . 'T08:00:00Z',
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: [ShipmentFactory::palletLine()],
        );

        $this->assertHasError('must not be later than shipper.pickupAtUntil', $request);
    }

    public function testHalfAPickupWindowIsRejected(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupAtFrom: ShipmentFactory::PICKUP_DATE . 'T08:00:00Z',
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: [ShipmentFactory::palletLine()],
        );

        $this->assertHasError('shipper.pickupAtUntil is required when pickupAtFrom is set', $request);
    }

    // -- product rules ------------------------------------------------

    public function testDeliveryOnIsRejectedOnANonFixProduct(): void
    {
        $request = $this->withDeliveryOn(Product::Standard, ShipmentFactory::DELIVERY_DATE);

        $this->assertHasError('consignee.deliveryOn may only be set for a FIX product', $request);
    }

    #[DataProvider('fixProducts')]
    public function testDeliveryOnIsAcceptedOnEveryFixProduct(Product $product): void
    {
        self::assertSame([], $this->validate($this->withDeliveryOn($product, ShipmentFactory::DELIVERY_DATE)));
    }

    /** @return iterable<string, array{Product}> */
    public static function fixProducts(): iterable
    {
        yield 'FIX'    => [Product::Fix];
        yield 'FIX_8'  => [Product::Fix8];
        yield 'FIX_10' => [Product::Fix10];
        yield 'FIX_12' => [Product::Fix12];
        yield 'FIX_16' => [Product::Fix16];
    }

    public function testDeliveryBeforePickupIsRejected(): void
    {
        $request = $this->withDeliveryOn(Product::Fix, '2026-08-19');

        $this->assertHasError('consignee.deliveryOn must not be earlier than shipper.pickupOn', $request);
    }

    public function testWeekendDeliveryIsRejected(): void
    {
        $request = $this->withDeliveryOn(Product::Fix, ShipmentFactory::WEEKEND_DATE);

        $this->assertHasError('consignee.deliveryOn must be a weekday', $request);
    }

    private function withDeliveryOn(Product $product, string $deliveryOn): ShipmentRequest
    {
        return new ShipmentRequest(
            product: $product,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupOn: ShipmentFactory::PICKUP_DATE,
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
                deliveryOn: $deliveryOn,
            ),
            lines: [ShipmentFactory::palletLine()],
        );
    }

    // -- line rules ---------------------------------------------------

    public function testAShipmentWithoutLinesIsRejected(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'), name: 'X', pickupOn: ShipmentFactory::PICKUP_DATE),
            consignee: new Consignee(address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'), name: 'Y'),
            lines: [],
        );

        self::assertContains('lines must contain at least one line.', $this->validate($request));
    }

    public function testEmptyContentZeroQuantityAndZeroDimensionsAreRejected(): void
    {
        $request = $this->withLines([new Line('  ', 0, PackageType::EuroPallet, 0, 0, 0, 0.0)]);
        $errors = $this->validate($request);

        self::assertContains('lines[0].content is required.', $errors);
        self::assertContains('lines[0].unitQuantity must be at least 1.', $errors);
        self::assertContains('lines[0].unitLength must be greater than 0 (dimensions are in centimetres).', $errors);
        self::assertContains('lines[0].unitWeight must be greater than 0 (weight is in kilograms, per unit).', $errors);
    }

    // -- loading metres -----------------------------------------------

    public function testTheLoadingMetreHelperProducesAValidLine(): void
    {
        $request = $this->withLines([Line::loadingMetres(7.2, 5800.0)]);

        self::assertSame([], $this->validate($request));
        self::assertSame(720, $request->lines[0]->unitLength);
        self::assertSame(7.2, $request->lines[0]->loadingMetreValue());
    }

    public function testALoadingMetreLineMustBe240WideAndAStandardHeight(): void
    {
        $request = $this->withLines([
            new Line('goods', 2, PackageType::LoadingMetres, 720, 200, 275, 5800.0),
        ]);

        $errors = $this->validate($request);

        self::assertContains('lines[0].unitWidth must be 240 for a PARTIE (loading-metre) line; got 200.', $errors);
        self::assertContains('lines[0].unitQuantity must be 1 for a PARTIE (loading-metre) line; the loading metres go into unitLength.', $errors);
        $this->assertHasError('unitHeight must be one of 250, 260, 300', $request);
    }

    // -- vehicles -----------------------------------------------------

    public function testA40TonneTruckMustBeBookedAsStandardOrExpress(): void
    {
        $request = $this->withLines([Line::vehicle(PackageType::DirectTruck40, 20000.0)], Product::Direct);

        $this->assertHasError('product must be STANDARD or EXPRESS when booking DIRECT_TRUCK_40', $request);
    }

    public function testASmallerVehicleMustBeBookedAsDirect(): void
    {
        $request = $this->withLines([Line::vehicle(PackageType::DirectTruck7_5, 2000.0)], Product::Standard);

        $this->assertHasError('product must be DIRECT when booking DIRECT_TRUCK_7_5', $request);
    }

    public function testVehiclePayloadCeilingIsEnforced(): void
    {
        $request = $this->withLines([Line::vehicle(PackageType::DirectCurtainVan, 1500.0)], Product::Direct);

        $this->assertHasError('exceeds the 1000 kg payload of DIRECT_BUS_PLANE', $request);
    }

    public function testAValidVehicleBookingPasses(): void
    {
        self::assertSame([], $this->validate($this->withLines([Line::vehicle(PackageType::DirectTruck12, 4800.0)], Product::Direct)));
        self::assertSame([], $this->validate($this->withLines([Line::vehicle(PackageType::DirectTruck40, 23000.0)], Product::Standard)));
    }

    public function testAVehicleLineMustNotBeMixedWithPackageLines(): void
    {
        $request = $this->withLines([
            Line::vehicle(PackageType::DirectTruck12, 4000.0),
            ShipmentFactory::palletLine(),
        ], Product::Direct);

        $this->assertHasError('must not mix a DIRECT_* vehicle line with ordinary package lines', $request);
    }

    public function testVehicleHelperRejectsANonVehiclePackageType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a vehicle package type');

        Line::vehicle(PackageType::EuroPallet, 100.0);
    }

    // -- insurance and value ------------------------------------------

    public function testInsuranceRequiresAGoodsValue(): void
    {
        $request = $this->withOptions(wantsInsurance: true);

        self::assertContains(
            'valueOfGoodsAmount is required (and must be greater than 0) when wantsInsurance is true.',
            $this->validate($request),
        );
    }

    public function testInsuranceWithAGoodsValueIsValid(): void
    {
        self::assertSame([], $this->validate($this->withOptions(wantsInsurance: true, valueOfGoodsAmount: 3000.0)));
    }

    public function testANonEuroGoodsCurrencyIsRejected(): void
    {
        $this->assertHasError('valueOfGoodsCurrency must be EUR', $this->withOptions(valueOfGoodsAmount: 100.0, valueOfGoodsCurrency: 'PLN'));
    }

    // -- dangerous goods ----------------------------------------------

    public function testADangerousGoodNeedsAUnNumberAndASubstanceName(): void
    {
        $line = new Line(
            content: 'Batteries',
            unitQuantity: 1,
            unitPackageType: PackageType::Carton,
            unitLength: 33,
            unitWidth: 31,
            unitHeight: 45,
            unitWeight: 11.0,
            dangerousGoods: [new DangerousGood('', '')],
        );

        $errors = $this->validate($this->withLines([$line]));

        self::assertContains('lines[0].dangerousGoods[0].unNo is required for a dangerous goods declaration.', $errors);
        self::assertContains('lines[0].dangerousGoods[0].substanceName is required for a dangerous goods declaration.', $errors);
    }

    // -- parcel rules -------------------------------------------------

    public function testParcelRejectsANonCartonPackageType(): void
    {
        $request = $this->withLines([new Line('Spare parts', 1, PackageType::OneWayPallet, 40, 30, 20, 5.0)]);

        $this->assertHasError('unitPackageType should be KT (carton/parcel)', $request, ValidationMode::Order, parcel: true);
    }

    public function testParcelEnforcesThe32KgPhysicalLimit(): void
    {
        $request = $this->withLines([new Line('Spare parts', 1, PackageType::Carton, 40, 30, 20, 33.0)]);

        $this->assertHasError('unitWeight of 33.0 kg exceeds the 32 kg parcel limit', $request, ValidationMode::Order, parcel: true);
    }

    public function testParcelEnforcesTheVolumetricWeightLimit(): void
    {
        // 100 x 76 x 30 / 5000 = 45.6 kg volumetric, while the physical weight is only 2 kg.
        $request = $this->withLines([new Line('Foam', 1, PackageType::Carton, 100, 76, 30, 2.0)]);

        $this->assertHasError('volumetric weight of 45.6 kg', $request, ValidationMode::Order, parcel: true);
    }

    public function testParcelEnforcesSideAndGirthLimits(): void
    {
        $request = $this->withLines([new Line('Pole', 1, PackageType::Carton, 120, 80, 10, 5.0)]);
        $errors = $this->validate($request, ValidationMode::Order, parcel: true);

        self::assertContains('lines[0] longest side of 120 cm exceeds the 100 cm parcel limit.', $errors);
        self::assertContains('lines[0] second-longest side of 80 cm exceeds the 76 cm parcel limit.', $errors);
    }

    public function testParcelEnforcesTheGirthLimitOnItsOwn(): void
    {
        // 100 + 2x76 + 2x50 = 352 cm girth, while each individual side is within limits.
        $request = $this->withLines([new Line('Box', 1, PackageType::Carton, 100, 76, 50, 5.0)]);

        $this->assertHasError('girth of 352 cm', $request, ValidationMode::Order, parcel: true);
    }

    public function testParcelEnforcesTheDailyPickupCount(): void
    {
        $request = $this->withLines([ShipmentFactory::parcelLine(21)]);

        $this->assertHasError('Cargoboard collects at most 20 per pickup location per day', $request, ValidationMode::Order, parcel: true);
    }

    public function testParcelRejectsEuToEuLanesThatAvoidGermany(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(address: new Address('00-001', CountryCode::PL, 'Warszawa', 'ul. Testowa 1'), name: 'Nadawca', pickupOn: ShipmentFactory::PICKUP_DATE),
            consignee: new Consignee(address: new Address('1000', CountryCode::AT, 'Wien', 'Teststrasse 1'), name: 'Empfänger'),
            lines: [ShipmentFactory::parcelLine()],
        );

        $this->assertHasError('parcel shipments must start or end in Germany', $request, ValidationMode::Order, parcel: true);
    }

    public function testParcelAllowsExportAndImportLanesThroughGermany(): void
    {
        $export = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'), name: 'Nadawca', pickupOn: ShipmentFactory::PICKUP_DATE),
            consignee: new Consignee(address: new Address('00-001', CountryCode::PL, 'Warszawa', 'ul. Testowa 1'), name: 'Odbiorca'),
            lines: [ShipmentFactory::parcelLine()],
        );

        self::assertSame([], $this->validate($export, ValidationMode::Order, parcel: true));
    }

    public function testParcelRejectsDangerousGoodsAndNeutralData(): void
    {
        $line = new Line(
            content: 'Batteries',
            unitQuantity: 1,
            unitPackageType: PackageType::Carton,
            unitLength: 33,
            unitWidth: 31,
            unitHeight: 45,
            unitWeight: 11.0,
            dangerousGoods: [new DangerousGood('3480', 'LITHIUM-IONEN-BATTERIEN')],
        );

        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'),
                name: 'Producer ABC GmbH',
                neutralData: new NeutralData('Selling Company ABC GmbH', 'Friedrichstraße 1', '10115', 'Berlin', CountryCode::DE),
                pickupOn: ShipmentFactory::PICKUP_DATE,
            ),
            consignee: new Consignee(address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'), name: 'Consignee ABC AG'),
            lines: [$line],
        );

        $errors = $this->validate($request, ValidationMode::Order, parcel: true);

        self::assertContains('neutralData is not supported for parcel shipments.', $errors);
        self::assertContains('dangerous goods (including LQ and EQ) are not available for parcel shipments.', $errors);
    }

    public function testParcelCapsTheInsurableGoodsValue(): void
    {
        $request = new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'), name: 'A', pickupOn: ShipmentFactory::PICKUP_DATE),
            consignee: new Consignee(address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'), name: 'B'),
            lines: [ShipmentFactory::parcelLine()],
            wantsInsurance: true,
            valueOfGoodsAmount: 50000.0,
        );

        $this->assertHasError('insurable maximum for parcel shipments', $request, ValidationMode::Order, parcel: true);
    }

    public function testParcelRulesDoNotApplyToFreight(): void
    {
        // The very same pallet shipment that fails as a parcel is a perfectly ordinary freight one.
        self::assertSame([], $this->validate(ShipmentFactory::bookable(), ValidationMode::Order, parcel: false));
    }

    /** @param list<Line> $lines */
    private function withLines(array $lines, Product $product = Product::Standard): ShipmentRequest
    {
        return new ShipmentRequest(
            product: $product,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupOn: ShipmentFactory::PICKUP_DATE,
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: $lines,
        );
    }

    private function withOptions(
        ?bool $wantsInsurance = null,
        ?float $valueOfGoodsAmount = null,
        ?string $valueOfGoodsCurrency = null,
    ): ShipmentRequest {
        return new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupOn: ShipmentFactory::PICKUP_DATE,
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: [ShipmentFactory::palletLine()],
            wantsInsurance: $wantsInsurance,
            valueOfGoodsAmount: $valueOfGoodsAmount,
            valueOfGoodsCurrency: $valueOfGoodsCurrency,
        );
    }
}
