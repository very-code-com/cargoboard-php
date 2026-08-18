<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One page of `GET /v1/orders`.
 *
 * Cargoboard paginates by cursor, and the cursor is the `sequence` of the last row rather than
 * an opaque token, so {@see self::nextCursor()} simply reads it off the final order.
 * {@see self::$total} is only filled in when the request asked for it
 * ({@see \VeryCodeCom\Cargoboard\Query\ListQuery::withTotal()}).
 *
 * @implements \IteratorAggregate<int, Order>
 */
final class OrderPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Order> $orders
     * @param list<Link>  $links
     */
    public function __construct(
        public readonly array $orders = [],
        public readonly ?float $total = null,
        public readonly array $links = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data  The decoded response body.
     * @param list<Link>           $links The response's `links` array.
     */
    public static function fromArray(array $data, array $links = []): self
    {
        return new self(
            orders: array_map(static fn (array $o): Order => Order::fromArray($o), Value::objectList($data, 'data')),
            total:  Value::float($data, 'total'),
            links:  $links,
        );
    }

    /** @return \ArrayIterator<int, Order> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->orders);
    }

    public function count(): int
    {
        return count($this->orders);
    }

    public function isEmpty(): bool
    {
        return $this->orders === [];
    }

    public function first(): ?Order
    {
        return $this->orders[0] ?? null;
    }

    /**
     * The cursor to pass to the next call, taken from the last row's `sequence`, or null when
     * this page is empty.
     */
    public function nextCursor(): ?float
    {
        $last = $this->orders[count($this->orders) - 1] ?? null;

        return $last?->sequence;
    }
}
