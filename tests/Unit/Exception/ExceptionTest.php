<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAuthException;
use VeryCodeCom\Cargoboard\Exception\CargoboardConflictException;
use VeryCodeCom\Cargoboard\Exception\CargoboardException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;
use VeryCodeCom\Cargoboard\Exception\CargoboardRateLimitException;
use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Exception\CargoboardServerException;
use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Exception\CargoboardValidationException;

final class ExceptionTest extends TestCase
{
    public function testEverythingDescendsFromTheBaseException(): void
    {
        foreach ([
            new CargoboardApiException('x'),
            new CargoboardAuthException('x', 403),
            new CargoboardNotFoundException('x', 404),
            new CargoboardConflictException('x', 409),
            new CargoboardUnprocessableEntityException('x', 422),
            new CargoboardRateLimitException('x', 429),
            new CargoboardServerException('x', 502),
            new CargoboardTransportException('x'),
            new CargoboardResponseParseException('x'),
            new CargoboardValidationException(['x']),
        ] as $exception) {
            self::assertInstanceOf(CargoboardException::class, $exception);
        }
    }

    public function testTheHttpSubtypesAreAllApiExceptions(): void
    {
        foreach ([
            new CargoboardAuthException('x', 403),
            new CargoboardNotFoundException('x', 404),
            new CargoboardConflictException('x', 409),
            new CargoboardUnprocessableEntityException('x', 422),
            new CargoboardRateLimitException('x', 429),
            new CargoboardServerException('x', 500),
        ] as $exception) {
            self::assertInstanceOf(CargoboardApiException::class, $exception);
        }
    }

    public function testTransportAndParseErrorsAreNotApiExceptions(): void
    {
        // A caller that catches CargoboardApiException is handling "the API said no", which a
        // timeout is not; keeping them apart is the point of the hierarchy.
        self::assertNotInstanceOf(CargoboardApiException::class, new CargoboardTransportException('x'));
        self::assertNotInstanceOf(CargoboardApiException::class, new CargoboardResponseParseException('x'));
        self::assertNotInstanceOf(CargoboardApiException::class, new CargoboardValidationException(['x']));
    }

    public function testApiExceptionCarriesStatusMessagesAndFieldNames(): void
    {
        $exception = new CargoboardApiException('failed', 422, [
            'shipper.address.countryCode must be one of the following values: AL, AT',
            'lines.0.unitWeight must be a positive number',
            'something without a field',
        ], 'Unprocessable Entity');

        self::assertSame(422, $exception->statusCode);
        self::assertSame(422, $exception->getCode());
        self::assertSame('Unprocessable Entity', $exception->error);
        self::assertTrue($exception->hasError('countryCode'));
        self::assertTrue($exception->hasError('COUNTRYCODE'), 'Matching is case-insensitive.');
        self::assertFalse($exception->hasError('deliveryOn'));
        self::assertSame(
            ['shipper.address.countryCode', 'lines.0.unitWeight', 'something'],
            $exception->getFieldNames(),
        );
    }

    public function testAuthExceptionDistinguishesMissingFromRejectedCredentials(): void
    {
        self::assertTrue((new CargoboardAuthException('x', 401))->isMissingCredentials());
        self::assertFalse((new CargoboardAuthException('x', 403))->isMissingCredentials());
    }

    public function testServerExceptionOnlyFlagsGatewayFailuresAsRetryable(): void
    {
        self::assertFalse((new CargoboardServerException('x', 500))->isRetryable());
        self::assertTrue((new CargoboardServerException('x', 502))->isRetryable());
        self::assertTrue((new CargoboardServerException('x', 503))->isRetryable());
        self::assertTrue((new CargoboardServerException('x', 504))->isRetryable());
    }

    public function testRateLimitExceptionIgnoresANonsenseRetryAfter(): void
    {
        self::assertNull((new CargoboardRateLimitException('x', 429))->retryAfterSeconds());
        self::assertSame(60, (new CargoboardRateLimitException('x', 429))->withRetryAfter(60)->retryAfterSeconds());
        self::assertNull((new CargoboardRateLimitException('x', 429))->withRetryAfter(0)->retryAfterSeconds());
        self::assertNull((new CargoboardRateLimitException('x', 429))->withRetryAfter(null)->retryAfterSeconds());
    }

    public function testValidationExceptionSummarisesItsErrors(): void
    {
        $exception = new CargoboardValidationException(['shipper.name is required.', 'lines must contain at least one line.']);

        self::assertCount(2, $exception->errors);
        self::assertStringContainsString('shipper.name is required.', $exception->getMessage());
        self::assertStringContainsString('lines must contain at least one line.', $exception->getMessage());
    }

    public function testDebugReportIncludesTheRawResponseAndTheCause(): void
    {
        $cause = new \RuntimeException('underlying');
        $exception = (new CargoboardApiException('failed', 500, [], '', $cause))->withRawResponse('{"statusCode":500}');

        $report = $exception->getDebugReport();

        self::assertStringContainsString('CargoboardApiException: failed', $report);
        self::assertStringContainsString('--- Raw Cargoboard response ---', $report);
        self::assertStringContainsString('{"statusCode":500}', $report);
        self::assertStringContainsString('--- Caused by ---', $report);
        self::assertStringContainsString('underlying', $report);
        self::assertStringContainsString('--- Stack trace ---', $report);
    }

    public function testDebugReportOmitsSectionsThatHaveNoContent(): void
    {
        $report = (new CargoboardTransportException('timeout'))->getDebugReport();

        self::assertStringNotContainsString('--- Raw Cargoboard response ---', $report);
        self::assertStringNotContainsString('--- Caused by ---', $report);
    }

    public function testWithRawResponseIsFluentAndKeepsTheConcreteType(): void
    {
        $exception = new CargoboardNotFoundException('nope', 404);

        self::assertSame($exception, $exception->withRawResponse('{}'));
        self::assertSame('{}', $exception->getRawResponse());
    }
}
