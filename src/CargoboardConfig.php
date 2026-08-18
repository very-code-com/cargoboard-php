<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard;

/**
 * Cargoboard client configuration.
 *
 * Cargoboard authenticates with a single API key sent as the `X-API-KEY` header. Keys are
 * issued **per environment**: a production key is rejected by the sandbox and vice versa, both
 * with HTTP 403. Request one from your Cargoboard contact or api@cargoboard.com; if the API is
 * already enabled for your account, the key is on https://my.cargoboard.com/account/integration.
 *
 * Three ways to build a config:
 *
 * 1. Named constructors (simplest):
 *      $config = CargoboardConfig::sandbox($apiKey);
 *      $config = CargoboardConfig::production($apiKey);
 *
 * 2. From environment variables (recommended for 12-factor apps):
 *      $config = CargoboardConfig::fromEnv();
 *
 *    Reads these env vars (set in .env, docker-compose, CI secrets, etc.):
 *      CARGOBOARD_API_KEY         - required
 *      CARGOBOARD_ENV             - "sandbox" | "production" (default: "production")
 *      CARGOBOARD_TIMEOUT         - int seconds (default: 30)
 *      CARGOBOARD_CONNECT_TIMEOUT - int seconds (default: 10)
 *      CARGOBOARD_PARCEL_MODE     - "1"/"true"/"yes"/"on" to send every request in parcel mode
 *      CARGOBOARD_DEBUG           - "1"/"true"/"yes"/"on" to enable debug reporting
 *
 * 3. From array (useful with framework config files):
 *      $config = CargoboardConfig::fromArray([
 *          'api_key'         => '...',
 *          'env'             => 'sandbox', // or 'production'
 *          'timeout'         => 30,
 *          'connect_timeout' => 10,
 *          'parcel_mode'     => false,
 *          'debug'           => false,
 *      ]);
 */
final class CargoboardConfig
{
    public const BASE_URL_SANDBOX    = 'https://api-sandbox.cargoboard.com';
    public const BASE_URL_PRODUCTION = 'https://api.cargoboard.com';

    /** Path prefix of the current API version; part of every endpoint URL. */
    public const API_VERSION = 'v1';

    /** Header carrying the API key on every request. */
    public const HEADER_API_KEY = 'X-API-KEY';

    /**
     * Header that switches `/v1/quotations` and `/v1/orders` from freight into parcel mode.
     * Without it, a parcel-shaped request is priced and booked as a normal freight shipment.
     */
    public const HEADER_PARCEL_MODE = 'x-transport-type-parcel-is-active';

    public function __construct(
        public readonly string $apiKey,
        public readonly bool $sandbox = false,
        /** Total request timeout (seconds) */
        public readonly int $timeout = 30,
        /** TCP connection timeout (seconds) */
        public readonly int $connectTimeout = 10,
        /**
         * Send every quotation and order in parcel mode (see {@see self::HEADER_PARCEL_MODE}).
         * Usually left false and switched on per call with
         * {@see CargoboardClient::withParcelMode()}, since most integrations mix both.
         */
        public readonly bool $parcelMode = false,
        /**
         * When true, the client attaches the raw Cargoboard response to thrown exceptions and
         * logs a full debug report (raw JSON + stack trace) for every error. Useful for
         * diagnosing unexpected errors against your own sandbox account.
         * Leave false in production to keep exceptions and logs concise.
         */
        public readonly bool $debug = false,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('CargoboardConfig: apiKey must not be empty.');
        }
    }

    // -- Named constructors ----------------------------------------------------

    /** Target the test system (api-sandbox.cargoboard.com); no truck ever drives. */
    public static function sandbox(string $apiKey): self
    {
        return new self($apiKey, sandbox: true);
    }

    /** Target the live system (api.cargoboard.com); a booking here is a real, billable transport. */
    public static function production(string $apiKey): self
    {
        return new self($apiKey, sandbox: false);
    }

    // -- Environment-variable factory ------------------------------------------

    /**
     * Build config from environment variables.
     *
     * Required: CARGOBOARD_API_KEY
     * Optional: CARGOBOARD_ENV, CARGOBOARD_TIMEOUT, CARGOBOARD_CONNECT_TIMEOUT,
     *           CARGOBOARD_PARCEL_MODE, CARGOBOARD_DEBUG
     *
     * @throws \InvalidArgumentException if CARGOBOARD_API_KEY is missing.
     */
    public static function fromEnv(): self
    {
        $get = static fn (string $key): string => (string) (getenv($key) ?: ($_ENV[$key] ?? $_SERVER[$key] ?? ''));
        $flag = static fn (string $value): bool => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);

        $apiKey = $get('CARGOBOARD_API_KEY');

        if ($apiKey === '') {
            throw new \InvalidArgumentException('CargoboardConfig::fromEnv(): CARGOBOARD_API_KEY env var is not set.');
        }

        $timeout = (int) ($get('CARGOBOARD_TIMEOUT') ?: 30);
        $connect = (int) ($get('CARGOBOARD_CONNECT_TIMEOUT') ?: 10);

        return new self(
            apiKey:         $apiKey,
            sandbox:        strtolower($get('CARGOBOARD_ENV') ?: 'production') === 'sandbox',
            timeout:        $timeout > 0 ? $timeout : 30,
            connectTimeout: $connect > 0 ? $connect : 10,
            parcelMode:     $flag($get('CARGOBOARD_PARCEL_MODE')),
            debug:          $flag($get('CARGOBOARD_DEBUG')),
        );
    }

    // -- Array factory ---------------------------------------------------------

    /**
     * Build config from an associative array.
     *
     * Keys: api_key*, env, timeout, connect_timeout, parcel_mode, debug
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException when "api_key" is missing.
     */
    public static function fromArray(array $config): self
    {
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($apiKey === '') {
            throw new \InvalidArgumentException('CargoboardConfig::fromArray(): "api_key" key is required.');
        }

        $timeout = (int) ($config['timeout'] ?? 30);
        $connect = (int) ($config['connect_timeout'] ?? 10);

        return new self(
            apiKey:         $apiKey,
            sandbox:        strtolower((string) ($config['env'] ?? 'production')) === 'sandbox',
            timeout:        $timeout > 0 ? $timeout : 30,
            connectTimeout: $connect > 0 ? $connect : 10,
            parcelMode:     (bool) ($config['parcel_mode'] ?? false),
            debug:          (bool) ($config['debug'] ?? false),
        );
    }

    // -- Helpers ---------------------------------------------------------------

    /** Host root of the selected environment, without a trailing slash. */
    public function getBaseUrl(): string
    {
        return $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    /**
     * Absolute URL of an API path.
     *
     * @param string $path Path below the version prefix, e.g. `orders/abc/tracking`.
     */
    public function url(string $path): string
    {
        return $this->getBaseUrl() . '/' . self::API_VERSION . '/' . ltrim($path, '/');
    }

    /** SSL verification is enabled for both environments; Cargoboard uses valid public certificates. */
    public function verifySsl(): bool
    {
        return true;
    }

    public function getEnvironment(): string
    {
        return $this->sandbox ? 'sandbox' : 'production';
    }

    /** The same config with parcel mode switched on or off. */
    public function withParcelMode(bool $enabled = true): self
    {
        if ($enabled === $this->parcelMode) {
            return $this;
        }

        return new self(
            apiKey:         $this->apiKey,
            sandbox:        $this->sandbox,
            timeout:        $this->timeout,
            connectTimeout: $this->connectTimeout,
            parcelMode:     $enabled,
            debug:          $this->debug,
        );
    }
}
