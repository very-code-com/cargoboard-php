<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\CargoboardConfig;
use VeryCodeCom\Cargoboard\Internal\Http\RequestBuilder;
use VeryCodeCom\Cargoboard\Query\ListQuery;
use VeryCodeCom\Cargoboard\Transport\TransportRequest;
use VeryCodeCom\Cargoboard\Transport\TransportResponse;

final class TransportTest extends TestCase
{
    // -- TransportResponse --------------------------------------------

    public function testResponseNormalisesHeaderNames(): void
    {
        $response = new TransportResponse(200, 'body', ['Content-Type' => 'application/json; charset=utf-8']);

        self::assertSame('application/json; charset=utf-8', $response->header('CONTENT-TYPE'));
        self::assertSame('application/json', $response->contentType());
        self::assertNull($response->header('x-missing'));
    }

    public function testResponseSuccessRange(): void
    {
        self::assertTrue((new TransportResponse(200, ''))->isSuccess());
        self::assertTrue((new TransportResponse(201, ''))->isSuccess());
        self::assertTrue((new TransportResponse(299, ''))->isSuccess());
        self::assertFalse((new TransportResponse(300, ''))->isSuccess());
        self::assertFalse((new TransportResponse(403, ''))->isSuccess());
    }

    public function testPdfIsDetectedByContentTypeOrByMagicBytes(): void
    {
        self::assertTrue((new TransportResponse(200, 'x', ['content-type' => 'application/pdf']))->isPdf());
        self::assertTrue((new TransportResponse(200, "%PDF-1.4\n..."))->isPdf());
        self::assertFalse((new TransportResponse(200, '{"data":{}}', ['content-type' => 'application/json']))->isPdf());
    }

    public function testRetryAfterOnlyParsesADelayInSeconds(): void
    {
        self::assertSame(120, (new TransportResponse(429, '', ['retry-after' => '120']))->retryAfterSeconds());
        // A HTTP-date Retry-After is valid but not a number of seconds; do not guess.
        self::assertNull((new TransportResponse(429, '', ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']))->retryAfterSeconds());
        self::assertNull((new TransportResponse(429, ''))->retryAfterSeconds());
    }

    // -- TransportRequest ---------------------------------------------

    public function testRedactedMasksCredentialsAndKeepsEverythingElse(): void
    {
        $request = new TransportRequest(
            url: 'https://api-sandbox.cargoboard.com/v1/orders',
            method: 'POST',
            body: '{"product":"STANDARD"}',
            headers: ['X-API-KEY' => 'super-secret-key-value', 'Content-Type' => 'application/json'],
        );

        $redacted = $request->redacted();

        self::assertStringNotContainsString('super-secret-key-value', $redacted->headers['X-API-KEY']);
        self::assertStringContainsString('[redacted]', $redacted->headers['X-API-KEY']);
        self::assertSame('application/json', $redacted->headers['Content-Type']);
        self::assertSame($request->url, $redacted->url);
        self::assertSame($request->body, $redacted->body);
        self::assertSame('super-secret-key-value', $request->headers['X-API-KEY'], 'The original is untouched.');
    }

    public function testRedactedHandlesAShortSecret(): void
    {
        $redacted = (new TransportRequest('https://x.test', headers: ['Authorization' => 'Bearer x']))->redacted();

        self::assertSame('[redacted]', $redacted->headers['Authorization']);
    }

    // -- RequestBuilder -----------------------------------------------

    private function builder(bool $parcelMode = false): RequestBuilder
    {
        return new RequestBuilder(new CargoboardConfig('key', sandbox: true, parcelMode: $parcelMode));
    }

    public function testRepeatedQueryParametersAreNotBracketed(): void
    {
        // http_build_query() would render filter[0]=..., which Cargoboard's validation rejects.
        $request = $this->builder()->get('orders', ListQuery::create()
            ->take(10)
            ->whereEquals('status', 'CREATED')
            ->whereEquals('postCodeFrom', '33100')
            ->orderBy('sequence')
            ->toQueryParameters());

        self::assertStringNotContainsString('filter[', $request->url);
        self::assertStringContainsString('filter=status%3D%22CREATED%22', $request->url);
        self::assertStringContainsString('filter=postCodeFrom%3D%2233100%22', $request->url);
        self::assertStringContainsString('orderBy=sequence', $request->url);
        self::assertSame(2, substr_count($request->url, 'filter='));
    }

    public function testGetSendsTheApiKeyAndNoParcelHeaderByDefault(): void
    {
        $request = $this->builder()->get('orders');

        self::assertSame('GET', $request->method);
        self::assertSame('https://api-sandbox.cargoboard.com/v1/orders', $request->url);
        self::assertSame('key', $request->headers['X-API-KEY']);
        self::assertSame('application/json', $request->headers['Accept']);
        self::assertArrayNotHasKey('x-transport-type-parcel-is-active', $request->headers);
    }

    public function testParcelModeCanComeFromTheConfigOrFromTheCall(): void
    {
        self::assertSame('true', $this->builder(parcelMode: true)->post('orders', ['a' => 1])->headers['x-transport-type-parcel-is-active']);
        self::assertSame('true', $this->builder()->post('orders', ['a' => 1], parcelMode: true)->headers['x-transport-type-parcel-is-active']);
    }

    public function testPostWithoutAPayloadStillSendsAnEmptyBody(): void
    {
        $request = $this->builder()->post('orders/1/cancel');

        self::assertSame('POST', $request->method);
        self::assertSame('', $request->body);
    }

    public function testPayloadIsEncodedWithReadableUnicodeAndPreservedFloats(): void
    {
        $request = $this->builder()->post('orders', ['city' => 'Mönchengladbach', 'weight' => 200.0]);

        self::assertNotNull($request->body);
        self::assertStringContainsString('Mönchengladbach', $request->body);
        self::assertStringContainsString('200.0', $request->body);
    }

    public function testPdfRequestAcceptsBothPdfAndJson(): void
    {
        // Cargoboard answers errors as JSON even on the PDF endpoints.
        $request = $this->builder()->getPdf('orders/1/print-shipment-labels', ['format' => 'A6']);

        self::assertStringContainsString('application/pdf', $request->headers['Accept']);
        self::assertStringContainsString('application/json', $request->headers['Accept']);
        self::assertStringEndsWith('?format=A6', $request->url);
    }

    public function testPathSegmentsArePercentEncoded(): void
    {
        self::assertSame('a%2Fb', RequestBuilder::pathSegment('a/b'));
        self::assertSame('10374504', RequestBuilder::pathSegment('10374504'));
    }

    public function testTimeoutsComeFromTheConfig(): void
    {
        $builder = new RequestBuilder(new CargoboardConfig('key', sandbox: true, timeout: 45, connectTimeout: 5));
        $request = $builder->get('orders');

        self::assertSame(45, $request->timeout);
        self::assertSame(5, $request->connectTimeout);
        self::assertTrue($request->verifySsl);
    }
}
