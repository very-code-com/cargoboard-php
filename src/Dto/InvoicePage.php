<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One page of `GET /v1/invoices`.
 *
 * Unlike the order and quotation lists, this endpoint makes `take` **mandatory**, so
 * {@see \VeryCodeCom\Cargoboard\CargoboardClient::listInvoices()} always sends one.
 *
 * @implements \IteratorAggregate<int, Invoice>
 */
final class InvoicePage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Invoice> $invoices
     * @param list<Link>    $links
     */
    public function __construct(
        public readonly array $invoices = [],
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
            invoices: array_map(static fn (array $i): Invoice => Invoice::fromArray($i), Value::objectList($data, 'data')),
            total:    Value::float($data, 'total'),
            links:    $links,
        );
    }

    /** @return \ArrayIterator<int, Invoice> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->invoices);
    }

    public function count(): int
    {
        return count($this->invoices);
    }

    public function isEmpty(): bool
    {
        return $this->invoices === [];
    }

    public function first(): ?Invoice
    {
        return $this->invoices[0] ?? null;
    }

    /** The cursor to pass to the next call, or null when this page is empty. */
    public function nextCursor(): ?float
    {
        $last = $this->invoices[count($this->invoices) - 1] ?? null;

        return $last?->sequence;
    }

    /**
     * Invoices that are unpaid and past their due date.
     *
     * @return list<Invoice>
     */
    public function overdue(?\DateTimeImmutable $now = null): array
    {
        return array_values(array_filter($this->invoices, static fn (Invoice $i): bool => $i->isOverdue($now)));
    }
}
