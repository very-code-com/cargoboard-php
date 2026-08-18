<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/** Transit time in working days, as a range: `daysMin` to `daysMax`. */
final class Runtime
{
    public function __construct(
        public readonly ?float $daysMin = null,
        public readonly ?float $daysMax = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            daysMin: Value::float($data, 'daysMin'),
            daysMax: Value::float($data, 'daysMax'),
        );
    }

    /** e.g. "1-2 days", or "1 day" when both ends agree. */
    public function __toString(): string
    {
        if ($this->daysMin === null && $this->daysMax === null) {
            return 'unknown';
        }
        if ($this->daysMin !== null && $this->daysMin === $this->daysMax) {
            return rtrim(rtrim(number_format($this->daysMin, 1, '.', ''), '0'), '.') . ' day(s)';
        }

        return sprintf('%s-%s days', $this->daysMin ?? '?', $this->daysMax ?? '?');
    }
}
