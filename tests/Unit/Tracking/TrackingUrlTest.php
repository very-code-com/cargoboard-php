<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Tracking;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\Co2Emission;
use VeryCodeCom\Cargoboard\Dto\DeliveryWindow;
use VeryCodeCom\Cargoboard\Dto\Order;
use VeryCodeCom\Cargoboard\Dto\OrderResult;
use VeryCodeCom\Cargoboard\Dto\Price;
use VeryCodeCom\Cargoboard\Dto\Runtime;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Tracking\TrackingUrl;

final class TrackingUrlTest extends TestCase
{
    public function testOrderIdLinkMatchesTheDocumentedShape(): void
    {
        self::assertSame(
            'https://my.cargoboard.com/tracking?order-id=cmrlp4gnl00133pbgdp6ghvkl&consignee-post-code=10115',
            TrackingUrl::forOrderId('cmrlp4gnl00133pbgdp6ghvkl', '10115'),
        );
    }

    public function testReferenceLinkMatchesTheDocumentedShape(): void
    {
        self::assertSame(
            'https://my.cargoboard.com/de/tracking?reference=11453140&secret=41061',
            TrackingUrl::forReference('11453140', '41061'),
        );
    }

    public function testReferenceLinkTakesALocale(): void
    {
        self::assertStringContainsString('/en/tracking?', TrackingUrl::forReference('1', '2', 'en'));
    }

    public function testValuesArePercentEncoded(): void
    {
        self::assertStringContainsString('consignee-post-code=00-001', TrackingUrl::forOrderId('a b', '00-001'));
        self::assertStringContainsString('order-id=a%20b', TrackingUrl::forOrderId('a b', '00-001'));
    }

    public function testForOrderPrefersThePlatformUrlTheApiReturned(): void
    {
        $order = $this->orderResult('https://my.cargoboard.com/tracking?order-id=given&consignee-post-code=85137');

        self::assertSame(
            'https://my.cargoboard.com/tracking?order-id=given&consignee-post-code=85137',
            TrackingUrl::forOrder($order, '85137'),
        );
    }

    public function testForOrderFallsBackToBuildingTheLink(): void
    {
        self::assertSame(
            'https://my.cargoboard.com/tracking?order-id=cm-1&consignee-post-code=85137',
            TrackingUrl::forOrder($this->orderResult(null), '85137'),
        );
    }

    public function testForStoredOrderTakesThePostCodeFromTheOrderItself(): void
    {
        $order = new Order(
            id: 'cm-stored',
            reference: '10374504',
            product: Product::Standard,
            shipper: new Shipper(address: new Address('58553', CountryCode::DE)),
            consignee: new Consignee(address: new Address('85137', CountryCode::DE)),
        );

        self::assertSame(
            'https://my.cargoboard.com/tracking?order-id=cm-stored&consignee-post-code=85137',
            TrackingUrl::forStoredOrder($order),
        );
    }

    private function orderResult(?string $platformTrackingUrl): OrderResult
    {
        return new OrderResult(
            id: 'cm-1',
            reference: '10374504',
            product: Product::Standard,
            price: new Price(10.0),
            priceStandard: new Price(10.0),
            runtime: new Runtime(),
            delivery: new DeliveryWindow(),
            co2Emission: new Co2Emission(),
            platformTrackingUrl: $platformTrackingUrl,
        );
    }
}
