<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Internal\Http;

use VeryCodeCom\Cargoboard\CargoboardConfig;
use VeryCodeCom\Cargoboard\Transport\TransportRequest;

/**
 * Turns an endpoint, a payload and a query into a {@see TransportRequest}.
 *
 * Two details are centralised here because getting either wrong is hard to spot:
 *
 *  - **Repeated query parameters.** Cargoboard's list endpoints take `orderBy` and `filter` as
 *    repeated parameters (`filter=a&filter=b`). PHP's `http_build_query()` would render those
 *    as `filter[0]=a&filter[1]=b`, which their validation rejects, so the query string is built
 *    by hand.
 *  - **The parcel-mode header.** `x-transport-type-parcel-is-active: true` is what separates a
 *    parcel booking from a freight booking; without it a parcel-shaped request is silently
 *    priced as freight.
 *
 * @internal
 */
final class RequestBuilder
{
    public function __construct(private readonly CargoboardConfig $config)
    {
    }

    /**
     * A JSON POST, e.g. placing a quotation or an order.
     *
     * @param array<string, mixed> $payload
     */
    public function post(string $path, array $payload = [], bool $parcelMode = false): TransportRequest
    {
        return new TransportRequest(
            url: $this->config->url($path),
            method: 'POST',
            body: $payload === [] ? '' : $this->encode($payload),
            headers: $this->headers(['Content-Type' => 'application/json'], $parcelMode),
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
            verifySsl: $this->config->verifySsl(),
        );
    }

    /**
     * A JSON GET.
     *
     * @param array<string, scalar|list<string>> $query
     */
    public function get(string $path, array $query = [], bool $parcelMode = false): TransportRequest
    {
        return new TransportRequest(
            url: $this->config->url($path) . $this->queryString($query),
            method: 'GET',
            headers: $this->headers([], $parcelMode),
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
            verifySsl: $this->config->verifySsl(),
        );
    }

    /**
     * A GET that expects a PDF stream (labels, order confirmation, invoice).
     *
     * @param array<string, scalar|list<string>> $query
     */
    public function getPdf(string $path, array $query = []): TransportRequest
    {
        return new TransportRequest(
            url: $this->config->url($path) . $this->queryString($query),
            method: 'GET',
            // Accept both: Cargoboard answers errors as JSON even on the PDF endpoints.
            headers: $this->headers(['Accept' => 'application/pdf, application/json']),
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
            verifySsl: $this->config->verifySsl(),
        );
    }

    /**
     * Percent-encode a value for use as a path segment. Order ids are CUIDs and references are
     * digits, but the API accepts either wherever it takes an `{id}`, and a caller may pass
     * something with a slash in it.
     */
    public static function pathSegment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra = [], bool $parcelMode = false): array
    {
        $headers = [
            CargoboardConfig::HEADER_API_KEY => $this->config->apiKey,
            'Accept' => 'application/json',
        ];

        if ($parcelMode || $this->config->parcelMode) {
            $headers[CargoboardConfig::HEADER_PARCEL_MODE] = 'true';
        }

        return array_merge($headers, $extra);
    }

    /**
     * @param array<string, scalar|list<string>> $query
     */
    private function queryString(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $pairs = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode($key) . '=' . rawurlencode($item);
                }
                continue;
            }

            $pairs[] = rawurlencode($key) . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        // JSON_UNESCAPED_UNICODE keeps German and Polish addresses readable in logs and on the
        // wire; JSON_PRESERVE_ZERO_FRACTION stops a 200.0 kg weight from becoming an integer.
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            throw new \InvalidArgumentException('Cargoboard request payload could not be encoded as JSON: ' . json_last_error_msg());
        }

        return $json;
    }
}
