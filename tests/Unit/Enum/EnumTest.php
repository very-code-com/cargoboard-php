<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\Incoterm;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Enum\ShipmentStatus;
use VeryCodeCom\Cargoboard\Enum\TrackingStepStatus;
use VeryCodeCom\Cargoboard\Enum\TrackingStepType;
use VeryCodeCom\Cargoboard\Enum\TruckType;

final class EnumTest extends TestCase
{
    public function testProductFamilies(): void
    {
        self::assertTrue(Product::Fix->isFix());
        self::assertTrue(Product::Fix10->isFix());
        self::assertFalse(Product::Standard->isFix());
        self::assertTrue(Product::Express8->isExpress());
        self::assertFalse(Product::Direct->isExpress());
    }

    public function testProductDeliveryDeadlines(): void
    {
        self::assertSame(8, Product::Express8->deliveryDeadlineHour());
        self::assertSame(12, Product::Fix12->deliveryDeadlineHour());
        self::assertNull(Product::Express->deliveryDeadlineHour());
        self::assertNull(Product::Standard->deliveryDeadlineHour());
    }

    public function testEveryDocumentedProductValueParses(): void
    {
        foreach (['STANDARD', 'EXPRESS', 'EXPRESS_8', 'EXPRESS_10', 'EXPRESS_12', 'EXPRESS_16', 'FIX', 'FIX_8', 'FIX_10', 'FIX_12', 'FIX_16', 'DIRECT'] as $value) {
            self::assertNotNull(Product::tryFrom($value), "Product {$value} must be supported.");
        }
    }

    public function testPackageTypeClassification(): void
    {
        self::assertTrue(PackageType::LoadingMetres->isLoadingMetres());
        self::assertFalse(PackageType::EuroPallet->isLoadingMetres());
        self::assertTrue(PackageType::DirectTruck40->isVehicle());
        self::assertFalse(PackageType::Carton->isVehicle());
    }

    public function testPackageTypeMapsToItsTruckTypeIncludingTailLiftVariants(): void
    {
        self::assertSame(TruckType::Truck12, PackageType::DirectTruck12->truckType());
        self::assertSame(TruckType::Truck12, PackageType::DirectTruck12TailLift->truckType());
        self::assertSame(TruckType::SprinterXxl, PackageType::DirectSprinterXxlTailLift->truckType());
        self::assertNull(PackageType::EuroPallet->truckType());
    }

    #[DataProvider('truckPayloads')]
    public function testTruckPayloadCeilings(TruckType $truck, int $expected): void
    {
        self::assertSame($expected, $truck->maxPayloadKg());
    }

    /** @return iterable<string, array{TruckType, int}> */
    public static function truckPayloads(): iterable
    {
        yield 'curtain-side van' => [TruckType::CurtainVan, 1000];
        yield 'sprinter XXL'     => [TruckType::SprinterXxl, 1000];
        yield 'truck 7.5t'       => [TruckType::Truck7_5, 2500];
        yield 'truck 12t'        => [TruckType::Truck12, 5000];
        yield 'truck 40t'        => [TruckType::Truck40, 24000];
    }

    public function testOnlyThe40TonneTruckRunsOnTheGroupageNetwork(): void
    {
        self::assertSame([Product::Standard, Product::Express], TruckType::Truck40->allowedProducts());
        self::assertSame([Product::Direct], TruckType::Truck7_5->allowedProducts());
    }

    public function testCountryCodeCoversCargoboardsServiceArea(): void
    {
        self::assertCount(32, CountryCode::cases());
        self::assertSame(CountryCode::DE, CountryCode::fromString('de'));
        self::assertSame(CountryCode::TR, CountryCode::fromString(' TR '));
    }

    public function testCountryCodeKnowsTheEuCustomsTerritory(): void
    {
        self::assertTrue(CountryCode::DE->isEuCustomsTerritory());
        self::assertTrue(CountryCode::PL->isEuCustomsTerritory());
        self::assertFalse(CountryCode::CH->isEuCustomsTerritory());
        self::assertFalse(CountryCode::GB->isEuCustomsTerritory());
        self::assertFalse(CountryCode::NO->isEuCustomsTerritory());
    }

    public function testIncoterm(): void
    {
        self::assertFalse(Incoterm::Standard->isDap());
        self::assertTrue(Incoterm::DapCleared->isDap());
        self::assertTrue(Incoterm::DapUncleared->isDap());
    }

    public function testShipmentStatusFinality(): void
    {
        self::assertTrue(ShipmentStatus::Delivered->isFinal());
        self::assertTrue(ShipmentStatus::Cancelled->isFinal());
        self::assertFalse(ShipmentStatus::Transit->isFinal());
    }

    public function testTrackingStepOrderIsTheJourneyOrder(): void
    {
        $ordered = TrackingStepType::cases();
        usort($ordered, static fn (TrackingStepType $a, TrackingStepType $b): int => $a->order() <=> $b->order());

        self::assertSame(TrackingStepType::Accepted, $ordered[0]);
        self::assertSame(TrackingStepType::Delivered, $ordered[count($ordered) - 1]);
        self::assertSame(7, TrackingStepType::Delivered->order());
    }

    public function testTrackingStepStatusReachedness(): void
    {
        self::assertTrue(TrackingStepStatus::Success->isReached());
        self::assertTrue(TrackingStepStatus::Warning->isReached());
        self::assertFalse(TrackingStepStatus::Pending->isReached());
        self::assertFalse(TrackingStepStatus::NoStatus->isReached());
    }
}
