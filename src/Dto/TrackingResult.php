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
 *  - {@see self::$events} is the raw status feed exactly as the API sent it, in the API's own
 *    order and with its duplicates: estimated windows, the signer's name, the driver's
 *    position, the partner handling the leg.
 *  - {@see self::timeline()} is that same feed tidied for storage or display: deduplicated on
 *    the event id and sorted oldest to newest. Render it with
 *    {@see TrackingEvent::describe()}, which never leaves you with a bare status number.
 *
 * Both lists are returned on every call; neither is a subset of the other. Nothing is dropped
 * on the way in - `$events` stays raw, so a caller who wants the untouched feed still has it.
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
     * The status feed, tidied: deduplicated and in chronological order, oldest first.
     *
     * This is the list to store or to render. {@see self::$events} keeps the raw feed for
     * anyone who needs it untouched.
     *
     * Deduplication is on {@see TrackingEvent::fingerprint()}, i.e. on the event id where the
     * API sends one. It is not on `(code, originatedAt)`: that pair is not unique - a shipment
     * notified by both SMS and e-mail produces several 722 events sharing a timestamp - and
     * keying on it silently loses one of them.
     *
     * @return list<TrackingEvent>
     */
    public function timeline(): array
    {
        $seen   = [];
        $unique = [];

        foreach ($this->events as $index => $event) {
            $key = $event->fingerprint();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[]   = [$index, $event];
        }

        usort($unique, static function (array $a, array $b): int {
            /** @var array{0: int, 1: TrackingEvent} $a */
            /** @var array{0: int, 1: TrackingEvent} $b */
            $left  = $a[1]->originatedAt;
            $right = $b[1]->originatedAt;

            // An event without a timestamp cannot be placed, so it keeps the API's order at
            // the end rather than being sorted to an invented position.
            if ($left === null || $right === null) {
                return (($left === null ? 1 : 0) <=> ($right === null ? 1 : 0))
                    ?: $a[0] <=> $b[0];
            }

            return ($left <=> $right) ?: $a[0] <=> $b[0];
        });

        return array_map(
            static function (array $entry): TrackingEvent {
                /** @var array{0: int, 1: TrackingEvent} $entry */
                return $entry[1];
            },
            $unique,
        );
    }

    /**
     * The delivery window as last estimated, taken from the most recent event that carries one.
     * Cargoboard refines this during the transport, so the newest estimate wins.
     */
    public function latestDeliveryWindow(): ?TrackingWindow
    {
        return $this->latestWindow(static fn (TrackingEvent $e): ?TrackingWindow => $e->deliveryWindow());
    }

    /** The collection window as last estimated; the newest estimate wins, as for delivery. */
    public function latestPickupWindow(): ?TrackingWindow
    {
        return $this->latestWindow(static fn (TrackingEvent $e): ?TrackingWindow => $e->pickupWindow());
    }

    /**
     * The delivery window as last estimated, as a plain array.
     *
     * Kept for callers written against 1.0; {@see self::latestDeliveryWindow()} returns the
     * same estimate as a {@see TrackingWindow}, which can format itself.
     *
     * @return array{from: ?\DateTimeImmutable, until: ?\DateTimeImmutable}|null
     */
    public function estimatedDelivery(): ?array
    {
        $window = $this->latestDeliveryWindow();

        return $window !== null ? ['from' => $window->from, 'until' => $window->until] : null;
    }

    /**
     * The window from the newest event that carries one.
     *
     * @param callable(TrackingEvent): ?TrackingWindow $extract
     */
    private function latestWindow(callable $extract): ?TrackingWindow
    {
        $best   = null;
        $bestAt = null;

        foreach ($this->timeline() as $event) {
            $window = $extract($event);

            if ($window === null) {
                continue;
            }

            // timeline() is already chronological, so a later event always wins; an event with
            // no timestamp only wins when nothing timestamped has been seen at all.
            if ($best === null || $bestAt === null || $event->originatedAt !== null) {
                $best   = $window;
                $bestAt = $event->originatedAt;
            }
        }

        return $best;
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
