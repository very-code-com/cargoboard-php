<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Enum\FilterOperator;
use VeryCodeCom\Cargoboard\Query\ListQuery;

final class ListQueryTest extends TestCase
{
    public function testAnEmptyQuerySendsNothing(): void
    {
        self::assertSame([], ListQuery::create()->toQueryParameters());
        self::assertNull(ListQuery::create()->takeValue());
    }

    public function testEveryParameterIsRendered(): void
    {
        $params = ListQuery::create()
            ->take(25)
            ->cursor(4711.0)
            ->orderBy('sequence')
            ->orderByDesc('createdAt')
            ->whereEquals('postCodeFrom', '33100')
            ->operator(FilterOperator::Or)
            ->withTotal()
            ->toQueryParameters();

        self::assertSame(25, $params['take']);
        self::assertSame(4711.0, $params['cursor']);
        self::assertSame(['sequence', 'createdAt:desc'], $params['orderBy']);
        self::assertSame(['postCodeFrom="33100"'], $params['filter']);
        self::assertSame('OR', $params['filterOperator']);
        self::assertSame('true', $params['total']);
    }

    public function testWhereEqualsQuotesStringsAndEscapesEmbeddedQuotes(): void
    {
        $params = ListQuery::create()
            ->whereEquals('status', 'CREATED')
            ->whereEquals('sequence', 42)
            ->whereEquals('isPaid', true)
            ->whereEquals('name', 'He said "no"')
            ->toQueryParameters();

        self::assertSame(
            ['status="CREATED"', 'sequence=42', 'isPaid=true', 'name="He said \"no\""'],
            $params['filter'],
        );
    }

    public function testRawExpressionsPassThroughUnchanged(): void
    {
        $params = ListQuery::create()->where('createdAt>="2024-01-01"')->orderByRaw('price:asc')->toQueryParameters();

        self::assertSame(['createdAt>="2024-01-01"'], $params['filter']);
        self::assertSame(['price:asc'], $params['orderBy']);
    }

    public function testTheQueryIsImmutable(): void
    {
        $base = ListQuery::create()->take(10);
        $derived = $base->take(20)->whereEquals('a', 'b');

        self::assertSame(10, $base->takeValue());
        self::assertArrayNotHasKey('filter', $base->toQueryParameters());
        self::assertSame(20, $derived->takeValue());
    }

    public function testWithTotalCanBeTurnedOffExplicitly(): void
    {
        self::assertSame('false', ListQuery::create()->withTotal(false)->toQueryParameters()['total']);
    }
}
