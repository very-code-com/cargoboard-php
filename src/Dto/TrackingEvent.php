<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One entry of the raw status event history: the detailed feed behind the milestones.
 *
 * The same events are what the Track & Trace webhook pushes in real time, so a polling
 * integration and a webhook integration see the same data
 * (see {@see \VeryCodeCom\Cargoboard\Webhook\WebhookEvent}).
 *
 * `code` is Cargoboard's numeric status code. There are some 450 of them, they are network
 * codes rather than a small enum, and their meanings are published as a spreadsheet rather than
 * in the API reference, so the code stays a string here; branch on the milestone
 * ({@see TrackingStep}) for logic and use `label`/`message` for display.
 *
 * The estimate fields are the ones worth wiring into your own UI: they carry the collection and
 * delivery windows as they get refined during the transport.
 */
final class TrackingEvent
{
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?\DateTimeImmutable $originatedAt = null,
        /** Human-readable description of the event, when Cargoboard sends one. */
        public readonly ?string $label = null,
        public readonly ?string $message = null,
        /** Id of the network partner that emitted the event. */
        public readonly ?string $emittedBy = null,
        /** Who signed for the goods; set on proof-of-delivery events. */
        public readonly ?string $nameOfSigner = null,
        public readonly ?float $waitingMinutes = null,
        public readonly ?float $vehicleLatitude = null,
        public readonly ?float $vehicleLongitude = null,
        public readonly ?\DateTimeImmutable $estimatedCollectionAtFrom = null,
        public readonly ?\DateTimeImmutable $estimatedCollectionAtUntil = null,
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
        );
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
