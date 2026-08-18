<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Internal\Json;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Enum\Product;
use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Internal\Json\DateFormat;
use VeryCodeCom\Cargoboard\Internal\Json\ResponseParser;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The readers that stand between a live JSON response and the DTOs. Their job is to be
 * unsurprising when the API sends something the schema did not promise.
 */
final class ValueAndParserTest extends TestCase
{
    // -- Value --------------------------------------------------------

    public function testStringReadsNumbersAndTreatsEmptyAsAbsent(): void
    {
        self::assertSame('abc', Value::string(['a' => 'abc'], 'a'));
        self::assertSame('42', Value::string(['a' => 42], 'a'));
        self::assertNull(Value::string(['a' => ''], 'a'));
        self::assertNull(Value::string(['a' => null], 'a'));
        self::assertNull(Value::string([], 'a'));
        self::assertNull(Value::string(['a' => ['x']], 'a'));
    }

    public function testNumbersAreReadFromStringsToo(): void
    {
        self::assertSame(42, Value::int(['a' => 42], 'a'));
        self::assertSame(42, Value::int(['a' => '42'], 'a'));
        self::assertSame(42, Value::int(['a' => 42.7], 'a'));
        self::assertNull(Value::int(['a' => 'x'], 'a'));

        self::assertSame(42.5, Value::float(['a' => 42.5], 'a'));
        self::assertSame(42.5, Value::float(['a' => '42.5'], 'a'));
        self::assertSame(42.0, Value::float(['a' => 42], 'a'));
        self::assertNull(Value::float(['a' => null], 'a'));
    }

    public function testBooleansAcceptTheShapesCargoboardActuallySends(): void
    {
        self::assertTrue(Value::bool(['a' => true], 'a'));
        self::assertFalse(Value::bool(['a' => false], 'a'));
        self::assertTrue(Value::bool(['a' => 'true'], 'a'));
        self::assertFalse(Value::bool(['a' => 'false'], 'a'));
        self::assertTrue(Value::bool(['a' => 1], 'a'));
        self::assertFalse(Value::bool(['a' => 0], 'a'));
        self::assertNull(Value::bool(['a' => 'maybe'], 'a'));
        self::assertNull(Value::bool([], 'a'));
    }

    public function testDateTimeHandlesBothDateAndTimestampShapes(): void
    {
        self::assertSame('2024-12-11', Value::dateTime(['a' => '2024-12-11'], 'a')?->format('Y-m-d'));
        self::assertSame('2024-12-11 07:00', Value::dateTime(['a' => '2024-12-11T07:00:00.000Z'], 'a')?->format('Y-m-d H:i'));
        self::assertNull(Value::dateTime(['a' => 'not a date'], 'a'));
        self::assertNull(Value::dateTime([], 'a'));
    }

    public function testObjectAndObjectListIgnoreWrongShapes(): void
    {
        self::assertSame(['x' => 1], Value::object(['a' => ['x' => 1]], 'a'));
        self::assertNull(Value::object(['a' => [1, 2]], 'a'), 'A JSON array is not an object.');
        self::assertNull(Value::object(['a' => 'x'], 'a'));

        self::assertSame([['x' => 1]], Value::objectList(['a' => [['x' => 1], 'skip me', 5]], 'a'));
        self::assertSame([], Value::objectList([], 'a'));
    }

    public function testStringListAcceptsBothAScalarAndAList(): void
    {
        self::assertSame(['one'], Value::stringList(['a' => 'one'], 'a'));
        self::assertSame(['one', 'two'], Value::stringList(['a' => ['one', 'two']], 'a'));
        self::assertSame(['1', '2'], Value::stringList(['a' => [1, 2]], 'a'));
        self::assertSame([], Value::stringList([], 'a'));
    }

    public function testUnknownEnumMembersParseToNullInsteadOfThrowing(): void
    {
        // An API that grows a new product must not break a client that only knows the old ones.
        self::assertSame(Product::Standard, Value::enum(['p' => 'STANDARD'], 'p', Product::class));
        self::assertNull(Value::enum(['p' => 'SOMETHING_NEW'], 'p', Product::class));
        self::assertNull(Value::enum([], 'p', Product::class));
    }

    // -- DateFormat ---------------------------------------------------

    public function testDateFormatNormalisesObjectsAndPassesStringsThrough(): void
    {
        self::assertSame('2026-08-20', DateFormat::date(new \DateTimeImmutable('2026-08-20 15:00:00')));
        self::assertSame('2026-08-20', DateFormat::date('2026-08-20'));
        self::assertNull(DateFormat::date(null));
        self::assertNull(DateFormat::date(''));
    }

    public function testDateFormatConvertsTimestampsToUtc(): void
    {
        $local = new \DateTimeImmutable('2026-08-20 10:00:00', new \DateTimeZone('Europe/Warsaw'));

        self::assertSame('2026-08-20T08:00:00Z', DateFormat::dateTime($local));
        self::assertSame('2026-08-20T08:00:00Z', DateFormat::dateTime('2026-08-20T08:00:00Z'));
    }

    public function testDateFormatParsesBackAndSurvivesGarbage(): void
    {
        self::assertSame('2026-08-20', DateFormat::parse('2026-08-20')?->format('Y-m-d'));
        self::assertNull(DateFormat::parse('nonsense'));
        self::assertNull(DateFormat::parse(null));
    }

    // -- ResponseParser -----------------------------------------------

    public function testDecodeRejectsEmptyMalformedAndNonObjectBodies(): void
    {
        $parser = new ResponseParser();

        self::assertSame(['data' => []], $parser->decode('{"data":[]}', 'op'));

        foreach (['', '   ', '{oops', '[1,2,3]', '"a string"'] as $body) {
            try {
                $parser->decode($body, 'op');
                self::fail(sprintf('Expected a parse exception for %s.', var_export($body, true)));
            } catch (CargoboardResponseParseException $e) {
                self::assertStringContainsString('op', $e->getMessage());
            }
        }
    }

    public function testDecodeAttachesTheRawBodyToTheException(): void
    {
        try {
            (new ResponseParser())->decode('{oops', 'op');
            self::fail('Expected a parse exception.');
        } catch (CargoboardResponseParseException $e) {
            self::assertSame('{oops', $e->getRawResponse());
        }
    }

    public function testDataRequiresTheEnvelopeButDataOrSelfDoesNot(): void
    {
        $parser = new ResponseParser();

        self::assertSame(['id' => 'x'], $parser->data(['data' => ['id' => 'x']], 'op'));
        self::assertSame(['id' => 'x'], $parser->dataOrSelf(['data' => ['id' => 'x']]));
        self::assertSame(['id' => 'x'], $parser->dataOrSelf(['id' => 'x']));

        $this->expectException(CargoboardResponseParseException::class);
        $this->expectExceptionMessage('has no "data" object');
        $parser->data(['links' => []], 'op');
    }
}
