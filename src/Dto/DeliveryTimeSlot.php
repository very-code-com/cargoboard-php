<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\DateFormat;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A booked delivery time slot, for consignees that run one, typically a retailer's or
 * marketplace's inbound dock booking system (Zeitfenstermanagement).
 *
 * Whoever books the slot (`performedBy`) records here which target it was booked with, the ASN
 * or PO references that identify it, and the resulting window. Cargoboard's schema leaves the
 * `performedBy` and `deliveryTarget` value sets open, so both are plain strings.
 */
final class DeliveryTimeSlot
{
    /** ISO-8601 timestamp, or null. */
    public readonly ?string $deliveryFrom;
    /** ISO-8601 timestamp, or null. */
    public readonly ?string $deliveryUntil;

    /**
     * @param string|null $performedBy           Who booked the slot, e.g. "CARGOBOARD".
     * @param string|null $deliveryTarget        The kind of target, e.g. "ANOTHER".
     * @param string|null $deliveryTargetAnother Free-text target name when `deliveryTarget` is "ANOTHER".
     * @param string|null $reference             Slot references, e.g. "ASN: 12345 - PO: 67890".
     * @param string|null $deeplinkUrl           Link back to the booking in the target's system.
     * @param string|\DateTimeInterface|null $deliveryFrom  Start of the booked window.
     * @param string|\DateTimeInterface|null $deliveryUntil End of the booked window.
     */
    public function __construct(
        public readonly ?string $performedBy = null,
        public readonly ?string $deliveryTarget = null,
        public readonly ?string $deliveryTargetAnother = null,
        public readonly ?string $reference = null,
        public readonly ?string $deeplinkUrl = null,
        string|\DateTimeInterface|null $deliveryFrom = null,
        string|\DateTimeInterface|null $deliveryUntil = null,
    ) {
        $this->deliveryFrom  = DateFormat::dateTime($deliveryFrom);
        $this->deliveryUntil = DateFormat::dateTime($deliveryUntil);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'performedBy'           => $this->performedBy,
            'deliveryTarget'        => $this->deliveryTarget,
            'deliveryTargetAnother' => $this->deliveryTargetAnother,
            'reference'             => $this->reference,
            'deeplinkUrl'           => $this->deeplinkUrl,
            'deliveryFrom'          => $this->deliveryFrom,
            'deliveryUntil'         => $this->deliveryUntil,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            performedBy:           Value::string($data, 'performedBy'),
            deliveryTarget:        Value::string($data, 'deliveryTarget'),
            deliveryTargetAnother: Value::string($data, 'deliveryTargetAnother'),
            reference:             Value::string($data, 'reference'),
            deeplinkUrl:           Value::string($data, 'deeplinkUrl'),
            deliveryFrom:          Value::string($data, 'deliveryFrom'),
            deliveryUntil:         Value::string($data, 'deliveryUntil'),
        );
    }
}
