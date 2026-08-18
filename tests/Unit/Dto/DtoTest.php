<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Dto;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Dto\AdrData;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\ContactPerson;
use VeryCodeCom\Cargoboard\Dto\DangerousGood;
use VeryCodeCom\Cargoboard\Dto\DeliveryTimeSlot;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\Link;
use VeryCodeCom\Cargoboard\Dto\NeutralData;
use VeryCodeCom\Cargoboard\Dto\Price;
use VeryCodeCom\Cargoboard\Dto\Runtime;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\LoadingType;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Tests\Support\ShipmentFactory;

/**
 * Covers the request DTOs' serialisation and the computed helpers the client and the validator
 * both rely on.
 */
final class DtoTest extends TestCase
{
    // -- Address ------------------------------------------------------

    public function testAddressSerialisesOnlyTheFieldsThatAreSet(): void
    {
        self::assertSame(
            ['postCode' => '10115', 'countryCode' => 'DE'],
            (new Address('10115', CountryCode::DE))->toArray(),
        );

        self::assertSame(
            ['postCode' => '10115', 'countryCode' => 'DE', 'city' => 'Berlin', 'street' => 'Examplestreet 12a'],
            (new Address('10115', CountryCode::DE, 'Berlin', 'Examplestreet 12a'))->toArray(),
        );
    }

    public function testAddressOfAcceptsALowercaseCountryString(): void
    {
        self::assertSame(CountryCode::PL, Address::of('00-001', 'pl')->countryCode);
    }

    public function testAddressOfRejectsACountryOutsideTheServiceArea(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not served by Cargoboard');

        Address::of('1000', 'US');
    }

    public function testAddressRendersOnOneLine(): void
    {
        self::assertSame(
            'Examplestreet 12a, DE-10115 Berlin',
            (string) new Address('10115', CountryCode::DE, 'Berlin', 'Examplestreet 12a'),
        );
    }

    // -- Shipper / Consignee ------------------------------------------

    public function testShipperNormalisesDateInputs(): void
    {
        $shipper = new Shipper(
            address: new Address('10115', CountryCode::DE),
            pickupOn: new \DateTimeImmutable('2026-08-20 13:45:00', new \DateTimeZone('Europe/Warsaw')),
            pickupAtFrom: new \DateTimeImmutable('2026-08-20 10:00:00', new \DateTimeZone('Europe/Warsaw')),
        );

        self::assertSame('2026-08-20', $shipper->pickupOn);
        // 10:00 in Warsaw (UTC+2 in August) is 08:00 UTC; sending it unconverted would move the window.
        self::assertSame('2026-08-20T08:00:00Z', $shipper->pickupAtFrom);
    }

    public function testShipperPassesStringDatesThroughUnchanged(): void
    {
        $shipper = new Shipper(address: new Address('10115', CountryCode::DE), pickupOn: '2026-08-20');

        self::assertSame('2026-08-20', $shipper->pickupOn);
        self::assertSame('2026-08-20', $shipper->pickupDate()?->format('Y-m-d'));
        self::assertTrue($shipper->hasPickupDate());
    }

    public function testShipperSerialisesNestedBlocksAndSkipsNulls(): void
    {
        $shipper = new Shipper(
            address: new Address('10115', CountryCode::DE, 'Berlin', 'Friedrichstraße 1'),
            name: 'Producer ABC GmbH',
            contactPerson: new ContactPerson('Mr. Skywalker', '+4917287502631', 'orders@example.com'),
            neutralData: new NeutralData('Selling Company', 'Friedrichstraße 1', '10115', 'Berlin', CountryCode::DE),
            pickupOn: '2026-08-20',
            wantsTailLiftTruck: true,
            loadingType: LoadingType::Ramp,
        );

        $array = $shipper->toArray();

        self::assertSame('Producer ABC GmbH', $array['name']);
        self::assertSame('Mr. Skywalker', $array['contactPerson']['name']);
        self::assertSame('Selling Company', $array['neutralData']['name']);
        self::assertSame('RAMP', $array['loadingType']);
        self::assertTrue($array['wantsTailLiftTruck']);
        self::assertArrayNotHasKey('freeTextForPickup', $array);
        self::assertArrayNotHasKey('wantsContactBeforePickup', $array);
    }

    public function testConsigneeSerialisesTheDeliveryTimeSlot(): void
    {
        $consignee = new Consignee(
            address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'),
            name: 'Consignee ABC AG',
            isPrivateCustomer: false,
            deliveryOn: '2026-08-21',
            deliveryTimeSlot: new DeliveryTimeSlot(
                performedBy: 'CARGOBOARD',
                deliveryTarget: 'ANOTHER',
                deliveryTargetAnother: 'Some warehouse',
                reference: 'ASN: 12345',
                deliveryFrom: '2026-08-21T11:00:00Z',
                deliveryUntil: '2026-08-21T12:00:00Z',
            ),
        );

        $array = $consignee->toArray();

        self::assertSame('2026-08-21', $array['deliveryOn']);
        self::assertSame('CARGOBOARD', $array['deliveryTimeSlot']['performedBy']);
        self::assertFalse($array['isPrivateCustomer'], 'An explicit false must be sent, not dropped.');
        self::assertSame('2026-08-21', $consignee->deliveryDate()?->format('Y-m-d'));
    }

    public function testContactPersonKnowsWhetherItIsReachable(): void
    {
        self::assertFalse((new ContactPerson('Nobody'))->isReachable());
        self::assertTrue((new ContactPerson('Somebody', '+49123'))->isReachable());
        self::assertTrue((new ContactPerson(email: 'a@b.c'))->isReachable());
    }

    // -- Line ---------------------------------------------------------

    public function testLineComputesWeightsVolumesAndSides(): void
    {
        $line = new Line('Tyres', 3, PackageType::EuroPallet, 120, 80, 100, 12.5);

        self::assertSame(37.5, $line->totalWeightKg());
        self::assertSame(0.96, round($line->unitVolumeM3(), 2));
        self::assertSame(2.88, round($line->totalVolumeM3(), 2));
        self::assertSame([120, 100, 80], $line->sortedSides());
        // 120 + 2x100 + 2x80 = 480
        self::assertSame(480, $line->girthCm());
        self::assertSame(192.0, $line->unitVolumetricWeightKg());
        self::assertNull($line->loadingMetreValue());
        self::assertFalse($line->hasDangerousGoods());
    }

    public function testLineSerialisesDangerousGoods(): void
    {
        $line = new Line(
            content: 'Batteries',
            unitQuantity: 1,
            unitPackageType: PackageType::Carton,
            unitLength: 33,
            unitWidth: 31,
            unitHeight: 45,
            unitWeight: 11.0,
            isStackable: false,
            dangerousGoods: [new DangerousGood('3480', 'LITHIUM-IONEN-BATTERIEN', riskMain: '9A', pointsTotal: 27)],
        );

        $array = $line->toArray();

        self::assertSame('KT', $array['unitPackageType']);
        self::assertFalse($array['isStackable']);
        self::assertArrayNotHasKey('wantsPalletExchange', $array);
        self::assertCount(1, $array['dangerousGoods']);
        self::assertSame('3480', $array['dangerousGoods'][0]['unNo']);
        self::assertTrue($line->hasDangerousGoods());
    }

    public function testLoadingMetreHelperPinsWidthAndConvertsMetresToCentimetres(): void
    {
        $line = Line::loadingMetres(7.2, 5800.0, 'goods', 300);

        self::assertSame(PackageType::LoadingMetres, $line->unitPackageType);
        self::assertSame(720, $line->unitLength);
        self::assertSame(240, $line->unitWidth);
        self::assertSame(300, $line->unitHeight);
        self::assertSame(5800.0, $line->unitWeight);
        self::assertSame(7.2, $line->loadingMetreValue());
    }

    public function testVehicleHelperBuildsASingleUnitLine(): void
    {
        $line = Line::vehicle(PackageType::DirectTruck12, 4800.0);

        self::assertSame(1, $line->unitQuantity);
        self::assertTrue($line->unitPackageType->isVehicle());
        self::assertSame(4800.0, $line->totalWeightKg());
    }

    // -- DangerousGood ------------------------------------------------

    public function testDangerousGoodComputesThePointsTotal(): void
    {
        $good = new DangerousGood('3480', 'LITHIUM-IONEN-BATTERIEN', pointsMultiplier: 3, weightNetOrVolume: 9.0);

        self::assertSame(27.0, $good->calculatePoints());
        self::assertFalse($good->exceedsThousandPoints());

        $heavy = new DangerousGood('1203', 'BENZIN', pointsMultiplier: 3, weightNetOrVolume: 500.0);
        self::assertTrue($heavy->exceedsThousandPoints());
    }

    public function testDangerousGoodOmitsUnsetFields(): void
    {
        $array = (new DangerousGood('3480', 'LITHIUM-IONEN-BATTERIEN', isLimitedQuantity: false))->toArray();

        self::assertSame(['unNo', 'substanceName', 'isLimitedQuantity'], array_keys($array));
        self::assertFalse($array['isLimitedQuantity'], 'An explicit false must survive serialisation.');
    }

    // -- ShipmentRequest ----------------------------------------------

    public function testShipmentRequestAggregatesItsLines(): void
    {
        $request = ShipmentFactory::parcel(3);

        self::assertSame(15.0, $request->totalWeightKg());
        self::assertSame(3, $request->totalUnits());
        self::assertFalse($request->hasDangerousGoods());
        self::assertFalse($request->crossesCustomsBorder());
    }

    public function testShipmentRequestDetectsACustomsBorder(): void
    {
        $request = new \VeryCodeCom\Cargoboard\Dto\ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(address: new Address('10115', CountryCode::DE)),
            consignee: new Consignee(address: new Address('8001', CountryCode::CH)),
            lines: [ShipmentFactory::palletLine()],
        );

        self::assertTrue($request->crossesCustomsBorder());
    }

    public function testShipmentRequestOmitsUnsetOptions(): void
    {
        $array = ShipmentFactory::quotable()->toArray();

        self::assertSame('STANDARD', $array['product']);
        self::assertArrayNotHasKey('wantsInsurance', $array);
        self::assertArrayNotHasKey('wantsClimateContribution', $array);
        self::assertArrayNotHasKey('incoterm', $array);
    }

    public function testWithersReturnCopies(): void
    {
        $base = ShipmentFactory::bookable();
        $express = $base->withProduct(Product::Express12);
        $coded = $base->withCustomerOrderCode('XYZ-1');

        self::assertSame(Product::Standard, $base->product);
        self::assertSame(Product::Express12, $express->product);
        self::assertNull($base->customerOrderCode);
        self::assertSame('XYZ-1', $coded->customerOrderCode);
        self::assertSame($base->lines, $express->lines);
    }

    // -- Response value objects ---------------------------------------

    public function testPriceExposesCentsAndRenders(): void
    {
        $price = new Price(90.85, 'EUR', 108.11, 17.26);

        self::assertSame(9085, $price->amountInCents());
        self::assertSame('90.85 EUR', (string) $price);
    }

    public function testRuntimeRenders(): void
    {
        self::assertSame('1-2 days', (string) new Runtime(1.0, 2.0));
        self::assertSame('1 day(s)', (string) new Runtime(1.0, 1.0));
        self::assertSame('unknown', (string) new Runtime());
    }

    public function testLinksAcceptBothAnArrayAndASingleObject(): void
    {
        $fromList = Link::listFromResponse(['links' => [
            ['rel' => 'self', 'method' => 'get', 'href' => 'https://example.test/a'],
            ['rel' => 'orderTrack', 'method' => 'GET', 'href' => 'https://example.test/b'],
        ]]);

        self::assertCount(2, $fromList);
        self::assertSame('GET', $fromList[0]->method, 'The method is normalised to upper case.');
        self::assertSame('https://example.test/b', Link::findRel($fromList, 'orderTrack')?->href);
        self::assertNull(Link::findRel($fromList, 'nope'));

        $fromObject = Link::listFromResponse(['links' => ['rel' => 'self', 'method' => 'GET', 'href' => 'https://example.test/c']]);
        self::assertCount(1, $fromObject);

        self::assertSame([], Link::listFromResponse([]));
    }

    // -- AdrData ------------------------------------------------------

    public function testAdrDataTurnsIntoAShipmentDeclaration(): void
    {
        $adr = new AdrData(
            unNo: '3480',
            substanceName: 'LITHIUM-IONEN-BATTERIEN',
            riskMain: '9A',
            subRisks: ['4.1'],
            classificationCode: 'M4',
            transportCategory: '2',
            tunnelRestriction: '(E)',
            adrVersion: 'ADR2023',
            pointsMultiplier: 3,
        );

        $good = $adr->toDangerousGood(quantity: 1, weightGross: 11.0, weightNetOrVolume: 9.0, packageType: 'Kiste');

        self::assertSame('3480', $good->unNo);
        self::assertSame('LITHIUM-IONEN-BATTERIEN', $good->substanceName);
        self::assertSame('9A', $good->riskMain);
        self::assertSame('4.1', $good->riskAdditional1);
        self::assertSame('Kiste', $good->packageType);
        self::assertSame(9.0, $good->weightNetOrVolume);
        self::assertSame('NGW', $good->weightNetOrVolumeUnit);
        // 3 (transport category multiplier) x 9 kg net = 27 points.
        self::assertSame(27, $good->pointsTotal);
    }
}
