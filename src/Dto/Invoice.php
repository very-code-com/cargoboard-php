<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * An invoice, as returned by `GET /v1/invoices` and embedded in an {@see Order}.
 *
 * Cargoboard invoices through Easybill, which shows in the field names: `documentId` and
 * `documentNumber` are Easybill's, and the `isDocument*` flags trace the document through
 * Easybill's own pipeline (completed, archived, sent, cancelled). For accounting purposes the
 * fields that matter are {@see self::$documentNumber}, {@see self::$documentAmount},
 * {@see self::$dueDate} and {@see self::$isPaid}.
 *
 * The PDF is fetched separately with
 * {@see \VeryCodeCom\Cargoboard\CargoboardClient::fetchInvoicePdf()}.
 *
 * @see https://docs.cargoboard.com/reference/get-invoices
 */
final class Invoice
{
    /**
     * @param list<string> $orderIds Ids of the orders billed on this invoice.
     */
    public function __construct(
        public readonly ?string $documentNumber = null,
        /** Easybill's numeric document id; the value to pass to fetchInvoicePdf(). */
        public readonly ?float $documentId = null,
        public readonly ?float $documentAmount = null,
        public readonly ?float $paymentAmount = null,
        public readonly string $paymentCurrency = 'EUR',
        public readonly ?bool $isPaid = null,
        public readonly ?\DateTimeImmutable $paidAt = null,
        public readonly ?\DateTimeImmutable $dueDate = null,
        public readonly ?float $dueInDays = null,
        public readonly ?bool $isDocumentCompleted = null,
        public readonly ?\DateTimeImmutable $documentCompletedAt = null,
        public readonly ?bool $isDocumentSent = null,
        public readonly ?\DateTimeImmutable $documentSentAt = null,
        public readonly ?bool $isDocumentCancelled = null,
        public readonly ?\DateTimeImmutable $documentCancelledAt = null,
        /** Id of the credit note that cancels this invoice, when it has been cancelled. */
        public readonly ?float $cancellationDocumentId = null,
        public readonly ?bool $isCancellationDocumentSent = null,
        public readonly ?\DateTimeImmutable $cancellationDocumentSentAt = null,
        public readonly ?bool $isDocumentCopiedToArchive = null,
        public readonly ?bool $isDocumentCopiedToArchiveZipped = null,
        public readonly ?\DateTimeImmutable $documentCopiedToArchiveAt = null,
        public readonly ?bool $isDone = null,
        public readonly ?float $sequence = null,
        public readonly ?string $customerId = null,
        public readonly array $orderIds = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $customer = Value::object($data, 'customer');

        $orderIds = [];
        foreach (Value::objectList($data, 'orders') as $order) {
            $id = Value::string($order, 'id');
            if ($id !== null) {
                $orderIds[] = $id;
            }
        }

        return new self(
            documentNumber:                  Value::string($data, 'documentNumber'),
            documentId:                      Value::float($data, 'documentId'),
            documentAmount:                  Value::float($data, 'documentAmount'),
            paymentAmount:                   Value::float($data, 'paymentAmount'),
            paymentCurrency:                 Value::string($data, 'paymentCurrency') ?? 'EUR',
            isPaid:                          Value::bool($data, 'isPaid'),
            paidAt:                          Value::dateTime($data, 'paidAt'),
            dueDate:                         Value::dateTime($data, 'dueDate'),
            dueInDays:                       Value::float($data, 'dueInDays'),
            isDocumentCompleted:             Value::bool($data, 'isDocumentCompleted'),
            documentCompletedAt:             Value::dateTime($data, 'documentCompletedAt'),
            isDocumentSent:                  Value::bool($data, 'isDocumentSent'),
            documentSentAt:                  Value::dateTime($data, 'documentSentAt'),
            isDocumentCancelled:             Value::bool($data, 'isDocumentCancelled'),
            documentCancelledAt:             Value::dateTime($data, 'documentCancelledAt'),
            cancellationDocumentId:          Value::float($data, 'cancellationDocumentId'),
            isCancellationDocumentSent:      Value::bool($data, 'isCancellationDocumentSent'),
            cancellationDocumentSentAt:      Value::dateTime($data, 'cancellationDocumentSentAt'),
            isDocumentCopiedToArchive:       Value::bool($data, 'isDocumentCopiedToArchive'),
            isDocumentCopiedToArchiveZipped: Value::bool($data, 'isDocumentCopiedToArchiveZipped'),
            documentCopiedToArchiveAt:       Value::dateTime($data, 'documentCopiedToArchiveAt'),
            isDone:                          Value::bool($data, 'isDone'),
            sequence:                        Value::float($data, 'sequence'),
            customerId:                      $customer !== null ? Value::string($customer, 'id') : null,
            orderIds:                        $orderIds,
        );
    }

    /** True when the invoice is unpaid and its due date has passed. */
    public function isOverdue(?\DateTimeImmutable $now = null): bool
    {
        if ($this->isPaid === true || $this->dueDate === null) {
            return false;
        }

        return $this->dueDate < ($now ?? new \DateTimeImmutable());
    }

    /** True when the invoice has been cancelled by a credit note. */
    public function isCancelled(): bool
    {
        return $this->isDocumentCancelled === true;
    }

    /** The id to pass to fetchInvoicePdf(), as a string. */
    public function pdfId(): ?string
    {
        return $this->documentId !== null ? (string) (int) $this->documentId : null;
    }
}
