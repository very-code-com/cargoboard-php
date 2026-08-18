<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Enum\TrackingStepType;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The answer to `GET /v1/orders/{id}/tracking`: where the shipment is, in two views.
 *
 *  - {@see self::$steps} is the milestone chain (accepted -> disposition -> picked up -> ... ->
 *    delivered), each marked reached, pending or warning. Use it to render progress.
 *  - {@see self::$events} is the raw status feed, newest information included: estimated
 *    windows, the signer's name, the driver's position, the partner handling the leg.
 *
 * Both are returned on every call; neither is a subset of the other.
 *
 * @see https://docs.cargoboard.com/reference/get-tracking-information-for-an-order
 */
final class TrackingResult
{
    /**
     * @param list<TrackingStep>  $steps  The milestone chain, in Cargoboard's order.
     * @param list<TrackingEvent> $events The raw status event history.
     */
    public function __construct(
        public readonly array $steps = [],
        public readonly array $events = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            steps:  array_map(static fn (array $s): TrackingStep => TrackingStep::fromArray($s), Value::objectList($data, 'trackingSteps')),
            events: array_map(static fn (array $e): TrackingEvent => TrackingEvent::fromArray($e), Value::objectList($data, 'statusEventHistory')),
        );
    }

    /** The furthest milestone the shipment has actually reached, or null if none has. */
    public function currentStep(): ?TrackingStep
    {
        $current = null;

        foreach ($this->steps as $step) {
            if ($step->isReached()) {
                $current = $step;
            }
        }

        return $current;
    }

    /** The step for one milestone, whether reached or not. */
    public function step(TrackingStepType $type): ?TrackingStep
    {
        foreach ($this->steps as $step) {
            if ($step->type === $type) {
                return $step;
            }
        }

        return null;
    }

    /** True once the DELIVERED milestone has been reached. */
    public function isDelivered(): bool
    {
        return $this->step(TrackingStepType::Delivered)?->isReached() ?? false;
    }

    /** True when any milestone came back as a warning, i.e. something needs attention. */
    public function hasWarning(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->status === \VeryCodeCom\Cargoboard\Enum\TrackingStepStatus::Warning) {
                return true;
            }
        }

        return false;
    }

    /** The most recent status event, by the time it happened. */
    public function latestEvent(): ?TrackingEvent
    {
        $latest = null;

        foreach ($this->events as $event) {
            if ($event->originatedAt === null) {
                continue;
            }
            if ($latest?->originatedAt === null || $event->originatedAt >= $latest->originatedAt) {
                $latest = $event;
            }
        }

        return $latest ?? ($this->events[0] ?? null);
    }

    /**
     * The delivery window as last estimated, taken from the most recent event that carries one.
     * Cargoboard refines this during the transport, so the newest estimate wins.
     *
     * @return array{from: ?\DateTimeImmutable, until: ?\DateTimeImmutable}|null
     */
    public function estimatedDelivery(): ?array
    {
        $best = null;

        foreach ($this->events as $event) {
            $from  = $event->estimatedDeliveryAtFrom ?? $event->estimatedArrivalAtFrom;
            $until = $event->estimatedDeliveryAtUntil ?? $event->estimatedArrivalAtUntil;

            if ($from === null && $until === null) {
                continue;
            }
            if ($best === null || ($event->originatedAt !== null && $best['at'] !== null && $event->originatedAt >= $best['at'])) {
                $best = ['from' => $from, 'until' => $until, 'at' => $event->originatedAt];
            }
        }

        return $best !== null ? ['from' => $best['from'], 'until' => $best['until']] : null;
    }

    /** The name of whoever signed for the goods, from the newest proof-of-delivery event. */
    public function signedBy(): ?string
    {
        $name = null;

        foreach ($this->events as $event) {
            if ($event->isProofOfDelivery()) {
                $name = $event->nameOfSigner;
            }
        }

        return $name;
    }
}
