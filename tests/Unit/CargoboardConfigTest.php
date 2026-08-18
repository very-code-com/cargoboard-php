<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\CargoboardConfig;

final class CargoboardConfigTest extends TestCase
{
    /** @var list<string> */
    private const ENV_KEYS = [
        'CARGOBOARD_API_KEY',
        'CARGOBOARD_ENV',
        'CARGOBOARD_TIMEOUT',
        'CARGOBOARD_CONNECT_TIMEOUT',
        'CARGOBOARD_PARCEL_MODE',
        'CARGOBOARD_DEBUG',
    ];

    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    public function testAnEmptyApiKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey must not be empty');

        new CargoboardConfig('   ');
    }

    public function testNamedConstructorsSelectTheRightHost(): void
    {
        self::assertSame('https://api-sandbox.cargoboard.com', CargoboardConfig::sandbox('k')->getBaseUrl());
        self::assertSame('https://api.cargoboard.com', CargoboardConfig::production('k')->getBaseUrl());
        self::assertSame('sandbox', CargoboardConfig::sandbox('k')->getEnvironment());
        self::assertSame('production', CargoboardConfig::production('k')->getEnvironment());
    }

    public function testUrlBuildsTheVersionedEndpoint(): void
    {
        $config = CargoboardConfig::sandbox('k');

        self::assertSame('https://api-sandbox.cargoboard.com/v1/orders', $config->url('orders'));
        self::assertSame('https://api-sandbox.cargoboard.com/v1/orders', $config->url('/orders'));
        self::assertSame('https://api-sandbox.cargoboard.com/v1/orders/1/tracking', $config->url('orders/1/tracking'));
    }

    public function testDefaults(): void
    {
        $config = CargoboardConfig::production('k');

        self::assertSame(30, $config->timeout);
        self::assertSame(10, $config->connectTimeout);
        self::assertFalse($config->parcelMode);
        self::assertFalse($config->debug);
        self::assertTrue($config->verifySsl());
    }

    public function testFromEnvRequiresTheApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CARGOBOARD_API_KEY env var is not set');

        CargoboardConfig::fromEnv();
    }

    public function testFromEnvReadsEveryOption(): void
    {
        putenv('CARGOBOARD_API_KEY=env-key');
        putenv('CARGOBOARD_ENV=sandbox');
        putenv('CARGOBOARD_TIMEOUT=45');
        putenv('CARGOBOARD_CONNECT_TIMEOUT=5');
        putenv('CARGOBOARD_PARCEL_MODE=yes');
        putenv('CARGOBOARD_DEBUG=1');

        $config = CargoboardConfig::fromEnv();

        self::assertSame('env-key', $config->apiKey);
        self::assertTrue($config->sandbox);
        self::assertSame(45, $config->timeout);
        self::assertSame(5, $config->connectTimeout);
        self::assertTrue($config->parcelMode);
        self::assertTrue($config->debug);
    }

    public function testFromEnvDefaultsToProductionAndIgnoresNonsenseTimeouts(): void
    {
        putenv('CARGOBOARD_API_KEY=env-key');
        putenv('CARGOBOARD_TIMEOUT=0');
        putenv('CARGOBOARD_CONNECT_TIMEOUT=-3');

        $config = CargoboardConfig::fromEnv();

        self::assertFalse($config->sandbox);
        self::assertSame(30, $config->timeout);
        self::assertSame(10, $config->connectTimeout);
    }

    public function testFromArrayRequiresTheApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"api_key" key is required');

        CargoboardConfig::fromArray(['env' => 'sandbox']);
    }

    public function testFromArrayReadsEveryOption(): void
    {
        $config = CargoboardConfig::fromArray([
            'api_key'         => 'array-key',
            'env'             => 'SANDBOX',
            'timeout'         => 60,
            'connect_timeout' => 15,
            'parcel_mode'     => true,
            'debug'           => true,
        ]);

        self::assertSame('array-key', $config->apiKey);
        self::assertTrue($config->sandbox);
        self::assertSame(60, $config->timeout);
        self::assertSame(15, $config->connectTimeout);
        self::assertTrue($config->parcelMode);
        self::assertTrue($config->debug);
    }

    public function testWithParcelModeReturnsACopyAndIsIdempotent(): void
    {
        $config = CargoboardConfig::sandbox('k');
        $parcel = $config->withParcelMode();

        self::assertFalse($config->parcelMode);
        self::assertTrue($parcel->parcelMode);
        self::assertSame($parcel, $parcel->withParcelMode(true), 'Switching to the current mode must not allocate.');
        self::assertFalse($parcel->withParcelMode(false)->parcelMode);
        self::assertSame('k', $parcel->apiKey);
    }
}
