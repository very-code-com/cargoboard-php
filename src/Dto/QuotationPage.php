<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One page of `GET /v1/quotations`; the quotation-side counterpart of {@see OrderPage}.
 *
 * @implements \IteratorAggregate<int, Quotation>
 */
final class QuotationPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Quotation> $quotations
     * @param list<Link>      $links
     */
    public function __construct(
        public readonly array $quotations = [],
        public readonly ?float $total = null,
        public readonly array $links = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<Link>           $links
     */
    public static function fromArray(array $data, array $links = []): self
    {
        return new self(
            quotations: array_map(static fn (array $q): Quotation => Quotation::fromArray($q), Value::objectList($data, 'data')),
            total:      Value::float($data, 'total'),
            links:      $links,
        );
    }

    /** @return \ArrayIterator<int, Quotation> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->quotations);
    }

    public function count(): int
    {
        return count($this->quotations);
    }

    public function isEmpty(): bool
    {
        return $this->quotations === [];
    }

    public function first(): ?Quotation
    {
        return $this->quotations[0] ?? null;
    }

    /** The cursor to pass to the next call, or null when this page is empty. */
    public function nextCursor(): ?float
    {
        $last = $this->quotations[count($this->quotations) - 1] ?? null;

        return $last?->sequence;
    }
}
