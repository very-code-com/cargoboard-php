<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The delivery window Cargoboard commits to for a quotation or order: `earliest` to `latest`.
 *
 * For a FIX product both ends collapse onto the requested day.
 */
final class DeliveryWindow
{
    public function __construct(
        public readonly ?\DateTimeImmutable $earliest = null,
        public readonly ?\DateTimeImmutable $latest = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            earliest: Value::dateTime($data, 'earliest'),
            latest:   Value::dateTime($data, 'latest'),
        );
    }

    /** True when both ends fall on the same calendar day. */
    public function isSingleDay(): bool
    {
        return $this->earliest !== null
            && $this->latest !== null
            && $this->earliest->format('Y-m-d') === $this->latest->format('Y-m-d');
    }
}
