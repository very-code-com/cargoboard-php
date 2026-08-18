<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\TrackingStepStatus;
use VeryCodeCom\Cargoboard\Enum\TrackingStepType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One milestone of a shipment's journey: the progress-bar view of tracking.
 *
 * A tracking response returns the whole chain of steps, reached and unreached alike, each with
 * its own {@see TrackingStepStatus}. That makes {@see TrackingResult::currentStep()} a matter of
 * finding the last reached one rather than interpreting raw event codes.
 */
final class TrackingStep
{
    public function __construct(
        public readonly ?TrackingStepType $type,
        public readonly ?TrackingStepStatus $status,
        /** Cargoboard's numeric status code for the event behind this step. */
        public readonly ?string $code = null,
        public readonly ?\DateTimeImmutable $originatedAt = null,
        public readonly ?float $sequence = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type:         Value::enum($data, 'type', TrackingStepType::class),
            status:       Value::enum($data, 'status', TrackingStepStatus::class),
            code:         Value::string($data, 'code'),
            originatedAt: Value::dateTime($data, 'originatedAt'),
            sequence:     Value::float($data, 'sequence'),
        );
    }

    /** True once this milestone has happened, successfully or with a warning. */
    public function isReached(): bool
    {
        return $this->status?->isReached() ?? false;
    }
}
