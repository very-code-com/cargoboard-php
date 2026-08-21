<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Format\EventNarrative;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One entry of the raw status event history: the detailed feed behind the milestones.
 *
 * `code` is Cargoboard's numeric status code. There are some 450 of them, they are network
 * codes rather than a small enum, and their meanings are published as a spreadsheet rather than
 * in the API reference, so the code stays a string here; branch on the milestone
 * ({@see TrackingStep}) for logic and use {@see self::describe()} for display.
 *
 * ## Which fields you actually get
 *
 * The event schema documented for `GET /v1/orders/{id}/tracking` and the payload the Track &
 * Trace webhook pushes are *not* the same object, though they overlap and the two were once
 * documented here as if they were. Measured against a live account (Aug 2026), every event of
 * that endpoint carries exactly these keys, present-but-null where there is no value:
 *
 *   id, originatedAt, code, message, nameOfSigner, stopsUntilCollection, stopsUntilDelivery,
 *   vehicleLatitude, vehicleLongitude, emittedBy, waitingMinutes,
 *   estimatedPickupAtFrom/Until, estimatedDeliveryAtFrom/Until
 *
 * The remaining properties on this class fall into two groups:
 *
 *  - {@see self::$label} appears **nowhere** in Cargoboard's OpenAPI definition and has never
 *    been observed on this endpoint. It belongs to the webhook payload
 *    ({@see \VeryCodeCom\Cargoboard\Webhook\WebhookEvent::$label}). Do not build a display on
 *    `label ?? message ?? code`: on a polling integration the first branch never fires and the
 *    third prints a bare `540`. Use {@see self::describe()}.
 *  - `location`, `createdAt`, `source`, `causedBy`, `deliveringPartnerOrderNumber`,
 *    `estimatedCollectionAt*` and `estimatedArrivalAt*` **are** in the OpenAPI definition (the
 *    last two are even marked required) but were absent from every event on that live account,
 *    which sent `estimatedPickupAt*`/`estimatedDeliveryAt*` in their place. They are kept
 *    because they are documented and other accounts or partners may populate them; treat them
 *    as optional extras, never as the field you read first. {@see self::pickupWindow()} and
 *    {@see self::deliveryWindow()} read both spellings so you do not have to.
 *
 * ## Identity
 *
 * {@see self::$id} is undocumented but present on every live event, and it is the only safe
 * deduplication key. `(code, originatedAt)` is **not** unique: one shipment carried three 722
 * events with the same timestamp - a phone notification, an e-mail notification and a repeat -
 * so storing on that pair silently drops events and, on a repair pass, overwrites one event's
 * row with another event's text.
 */
final class TrackingEvent
{
    /**
     * @param string|null $id Undocumented, but sent on every live event and stable across
     *                        polls. The deduplication key; see the class docblock for why
     *                        `(code, originatedAt)` is not one.
     */
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?\DateTimeImmutable $originatedAt = null,
        /** Webhook-only in practice: never observed on the tracking endpoint. See the class docblock. */
        public readonly ?string $label = null,
        public readonly ?string $message = null,
        /** Id of the network partner that emitted the event. */
        public readonly ?string $emittedBy = null,
        /** Who signed for the goods; set on proof-of-delivery events. */
        public readonly ?string $nameOfSigner = null,
        public readonly ?float $waitingMinutes = null,
        public readonly ?float $vehicleLatitude = null,
        public readonly ?float $vehicleLongitude = null,
        /** Documented, but live accounts send `estimatedPickupAt*` instead. */
        public readonly ?\DateTimeImmutable $estimatedCollectionAtFrom = null,
        public readonly ?\DateTimeImmutable $estimatedCollectionAtUntil = null,
        /** Documented, but live accounts send `estimatedDeliveryAt*` instead. */
        public readonly ?\DateTimeImmutable $estimatedArrivalAtFrom = null,
        public readonly ?\DateTimeImmutable $estimatedArrivalAtUntil = null,
        public readonly ?\DateTimeImmutable $estimatedPickupAtFrom = null,
        public readonly ?\DateTimeImmutable $estimatedPickupAtUntil = null,
        public readonly ?\DateTimeImmutable $estimatedDeliveryAtFrom = null,
        public readonly ?\DateTimeImmutable $estimatedDeliveryAtUntil = null,
        /** How many stops the driver still has before this shipment. */
        public readonly ?float $stopsUntilCollection = null,
        public readonly ?float $stopsUntilDelivery = null,
        public readonly ?string $deliveringPartnerOrderNumber = null,
        /** System that emitted the event, e.g. "CARGOBOARD". */
        public readonly ?string $source = null,
        /** What triggered it, e.g. "POD_UPLOADED". */
        public readonly ?string $causedBy = null,
        public readonly ?TrackingLocation $location = null,
        /** When the event was recorded, which can lag behind when it happened. */
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?string $id = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $location = Value::object($data, 'location');

        return new self(
            code:                        Value::string($data, 'code'),
            originatedAt:                Value::dateTime($data, 'originatedAt'),
            label:                       Value::string($data, 'label'),
            message:                     Value::string($data, 'message'),
            emittedBy:                   Value::string($data, 'emittedBy'),
            nameOfSigner:                Value::string($data, 'nameOfSigner'),
            waitingMinutes:              Value::float($data, 'waitingMinutes'),
            vehicleLatitude:             Value::float($data, 'vehicleLatitude'),
            vehicleLongitude:            Value::float($data, 'vehicleLongitude'),
            estimatedCollectionAtFrom:   Value::dateTime($data, 'estimatedCollectionAtFrom'),
            estimatedCollectionAtUntil:  Value::dateTime($data, 'estimatedCollectionAtUntil'),
            estimatedArrivalAtFrom:      Value::dateTime($data, 'estimatedArrivalAtFrom'),
            estimatedArrivalAtUntil:     Value::dateTime($data, 'estimatedArrivalAtUntil'),
            estimatedPickupAtFrom:       Value::dateTime($data, 'estimatedPickupAtFrom'),
            estimatedPickupAtUntil:      Value::dateTime($data, 'estimatedPickupAtUntil'),
            estimatedDeliveryAtFrom:     Value::dateTime($data, 'estimatedDeliveryAtFrom'),
            estimatedDeliveryAtUntil:    Value::dateTime($data, 'estimatedDeliveryAtUntil'),
            stopsUntilCollection:        Value::float($data, 'stopsUntilCollection'),
            stopsUntilDelivery:          Value::float($data, 'stopsUntilDelivery'),
            deliveringPartnerOrderNumber: Value::string($data, 'deliveringPartnerOrderNumber'),
            source:                      Value::string($data, 'source'),
            causedBy:                    Value::string($data, 'causedBy'),
            location:                    $location !== null ? TrackingLocation::fromArray($location) : null,
            createdAt:                   Value::dateTime($data, 'createdAt'),
            id:                          Value::string($data, 'id'),
        );
    }

    /**
     * The collection window as this event estimates it, or null when it carries none.
     *
     * Reads `estimatedPickupAt*` first (what live accounts send) and falls back to
     * `estimatedCollectionAt*` (what the OpenAPI definition documents).
     */
    public function pickupWindow(): ?TrackingWindow
    {
        return TrackingWindow::of(
            $this->estimatedPickupAtFrom ?? $this->estimatedCollectionAtFrom,
            $this->estimatedPickupAtUntil ?? $this->estimatedCollectionAtUntil,
        );
    }

    /**
     * The delivery window as this event estimates it, or null when it carries none.
     *
     * Reads `estimatedDeliveryAt*` first, falling back to the documented `estimatedArrivalAt*`.
     */
    public function deliveryWindow(): ?TrackingWindow
    {
        return TrackingWindow::of(
            $this->estimatedDeliveryAtFrom ?? $this->estimatedArrivalAtFrom,
            $this->estimatedDeliveryAtUntil ?? $this->estimatedArrivalAtUntil,
        );
    }

    /** True when this event refines either window - i.e. it says something even if `message` is null. */
    public function hasEstimates(): bool
    {
        return $this->pickupWindow() !== null || $this->deliveryWindow() !== null;
    }

    /**
     * The estimates this event carries, rendered on their own, or null when it carries none:
     *
     *   Estimates updated: collection 18.08.2026 07:00-15:00, delivery 19.08.2026 06:00 - 21.08.2026 14:00
     *
     * Append it to {@see self::$message} when you want both halves on one line.
     */
    public function estimateSummary(?\DateTimeZone $timezone = null): ?string
    {
        return EventNarrative::estimates($this->pickupWindow(), $this->deliveryWindow(), $timezone);
    }

    /**
     * A line fit to show a customer: the text the API sent, else the signature it carries, else
     * the estimates it carries, else `Status {code}`.
     *
     *   540   Estimates updated: collection 18.08.2026 07:00-15:00, delivery 19.08.2026 ...
     *   510   BO26082222
     *   831   The shipment has arrived at the delivery depot
     *   700   Signed by A. Nowak
     *
     * Never returns an empty string, and never invents a meaning for a numeric code.
     * Pass the timezone you display in; timestamps are otherwise rendered as the API sent them,
     * which is UTC.
     */
    public function describe(?\DateTimeZone $timezone = null): string
    {
        return EventNarrative::describe(
            $this->label,
            $this->message,
            $this->code,
            $this->pickupWindow(),
            $this->deliveryWindow(),
            $timezone,
            $this->nameOfSigner,
        );
    }

    /**
     * A key that identifies this event for storage and deduplication.
     *
     * The API's `id` when there is one. Otherwise a composite of everything that distinguishes
     * one event from another, because `(code, originatedAt)` alone collapses the notification
     * events that share a timestamp.
     */
    public function fingerprint(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        return implode('|', [
            $this->code ?? '',
            $this->originatedAt?->format(\DateTimeInterface::ATOM) ?? '',
            $this->message ?? '',
            $this->emittedBy ?? '',
            $this->nameOfSigner ?? '',
        ]);
    }

    /** True when the driver's position was reported with this event. */
    public function hasVehiclePosition(): bool
    {
        return $this->vehicleLatitude !== null && $this->vehicleLongitude !== null;
    }

    /** True when this event carries a signature, i.e. it is a proof of delivery. */
    public function isProofOfDelivery(): bool
    {
        return $this->nameOfSigner !== null && $this->nameOfSigner !== '';
    }
}
