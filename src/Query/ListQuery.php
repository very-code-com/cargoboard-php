<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Query;

use VeryCodeCom\Cargoboard\Enum\FilterOperator;

/**
 * Query parameters for the three list endpoints (`/v1/orders`, `/v1/quotations`, `/v1/invoices`).
 *
 * Immutable and fluent, so a query reads as one expression:
 *
 *   $query = ListQuery::create()
 *       ->take(25)
 *       ->whereEquals('shipmentStatus', 'DELIVERED')
 *       ->whereEquals('customerOrderCode', 'XYZ-1568788')
 *       ->operator(FilterOperator::And)
 *       ->orderByDesc('sequence')
 *       ->withTotal();
 *
 *   $page = $client->listOrders($query);
 *
 * **Filter syntax.** Cargoboard takes filters as repeated `filter=` parameters holding
 * `field="value"` expressions - the quotes are part of the value, which is what
 * {@see self::whereEquals()} takes care of. Their documentation gives one example
 * (`postCodeFrom="33100"`) and does not publish the list of filterable fields or of comparison
 * operators, so {@see self::where()} passes an arbitrary expression through unchanged for
 * anything beyond equality.
 *
 * **Pagination** is by cursor, not by offset: take a page, then pass the previous page's
 * {@see \VeryCodeCom\Cargoboard\Dto\OrderPage::nextCursor()} into {@see self::cursor()}.
 */
final class ListQuery
{
    /**
     * Largest page size the API accepts. `take=51` is rejected with
     * 422 "take must not be greater than 50"; `take=0` is accepted.
     */
    public const MAX_TAKE = 50;

    /**
     * @param list<string> $orderBy Sort expressions, in Cargoboard's own syntax.
     * @param list<string> $filters Filter expressions, e.g. `status="CREATED"`.
     */
    private function __construct(
        private readonly ?int $take = null,
        private readonly ?float $cursor = null,
        private readonly array $orderBy = [],
        private readonly array $filters = [],
        private readonly ?FilterOperator $filterOperator = null,
        private readonly ?bool $total = null,
    ) {
    }

    /** An empty query; every list endpoint accepts one. */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Page size, capped at {@see self::MAX_TAKE}.
     *
     * The endpoints disagree about whether this is optional: `/v1/quotations` applies its own
     * default, while `/v1/orders` and `/v1/invoices` answer 422 when it is missing. The client
     * fills one in for those two, so an empty query works everywhere.
     */
    public function take(int $take): self
    {
        return $this->with(take: $take);
    }

    /** Start the page after this cursor, i.e. after the row with this `sequence`. */
    public function cursor(?float $cursor): self
    {
        return $this->with(cursor: $cursor);
    }

    /** Sort ascending by a field, appending to any sort already set. */
    public function orderBy(string $field): self
    {
        return $this->with(orderBy: [...$this->orderBy, $field]);
    }

    /**
     * Sort descending by a field. Cargoboard's list endpoints document `orderBy` only as an
     * array of strings, so the direction is expressed the way their platform reads it,
     * `field:desc`; use {@see self::orderByRaw()} if your account expects another syntax.
     */
    public function orderByDesc(string $field): self
    {
        return $this->with(orderBy: [...$this->orderBy, $field . ':desc']);
    }

    /** Append a sort expression verbatim. */
    public function orderByRaw(string $expression): self
    {
        return $this->with(orderBy: [...$this->orderBy, $expression]);
    }

    /**
     * Filter on a field being equal to a value, quoting it the way Cargoboard expects:
     * `whereEquals('postCodeFrom', '33100')` becomes `postCodeFrom="33100"`.
     */
    public function whereEquals(string $field, string|int|float|bool $value): self
    {
        $rendered = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => '"' . str_replace('"', '\"', $value) . '"',
            default => (string) $value,
        };

        return $this->with(filters: [...$this->filters, $field . '=' . $rendered]);
    }

    /** Append a filter expression verbatim, for anything equality cannot express. */
    public function where(string $expression): self
    {
        return $this->with(filters: [...$this->filters, $expression]);
    }

    /** How several filters combine; Cargoboard defaults to AND. */
    public function operator(FilterOperator $operator): self
    {
        return $this->with(filterOperator: $operator);
    }

    /** Ask for the total row count alongside the page. */
    public function withTotal(bool $total = true): self
    {
        return $this->with(total: $total);
    }

    /**
     * Render as query parameters. Repeated parameters (`orderBy`, `filter`) are returned as
     * lists so the URL builder can emit `filter=a&filter=b` rather than a bracketed array,
     * which is the shape Cargoboard's NestJS validation expects.
     *
     * @return array<string, scalar|list<string>>
     */
    public function toQueryParameters(): array
    {
        $params = [];

        if ($this->take !== null) {
            $params['take'] = $this->take;
        }
        if ($this->cursor !== null) {
            $params['cursor'] = $this->cursor;
        }
        if ($this->orderBy !== []) {
            $params['orderBy'] = $this->orderBy;
        }
        if ($this->filters !== []) {
            $params['filter'] = $this->filters;
        }
        if ($this->filterOperator !== null) {
            $params['filterOperator'] = $this->filterOperator->value;
        }
        if ($this->total !== null) {
            $params['total'] = $this->total ? 'true' : 'false';
        }

        return $params;
    }

    /** The page size this query asks for, or null when it leaves the default in place. */
    public function takeValue(): ?int
    {
        return $this->take;
    }

    /**
     * @param list<string>|null $orderBy
     * @param list<string>|null $filters
     */
    private function with(
        ?int $take = null,
        ?float $cursor = null,
        ?array $orderBy = null,
        ?array $filters = null,
        ?FilterOperator $filterOperator = null,
        ?bool $total = null,
    ): self {
        return new self(
            take:           $take ?? $this->take,
            cursor:         $cursor ?? $this->cursor,
            orderBy:        $orderBy ?? $this->orderBy,
            filters:        $filters ?? $this->filters,
            filterOperator: $filterOperator ?? $this->filterOperator,
            total:          $total ?? $this->total,
        );
    }
}
