<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Webhook;

use VeryCodeCom\Cargoboard\Dto\TrackingWindow;
use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Internal\Format\EventNarrative;
use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * One Track & Trace status update, as pushed to your webhook endpoint.
 *
 * Cargoboard sends these by HTTPS POST the moment a status is generated, which is how you get
 * real-time tracking without polling `GET /v1/orders/{id}/tracking`. The webhook is configured
 * on their side: send your endpoint URL, any authentication details and your customer number to
 * api@cargoboard.com and they wire it up.
 *
 * Typical handler:
 *
 *   $event = WebhookEvent::fromJson(file_get_contents('php://input'));
 *
 *   if ($event->isProduction()) {
 *       $shipment = $repository->findByCargoboardReference($event->reference);
 *       $shipment->applyStatus($event->statusCode, $event->describe(), $event->originatedAt);
 *   }
 *
 * Note the two reference fields and which is whose: {@see self::$reference} is Cargoboard's
 * shipment number, {@see self::$customerOrderCode} is the reference *you* sent when booking.
 * Matching on the latter is usually what an ERP wants.
 *
 * Cargoboard does not document a signature or shared secret for these calls, so authenticate
 * them the way you agreed with them when the endpoint was registered (a bearer token or basic
 * auth on your endpoint, plus an allowlist), and treat the payload as untrusted input.
 *
 * @see https://docs.cargoboard.com/reference/track-trace-shipment-status
 */
final class WebhookEvent
{
    /** @param array<string, mixed> $raw The payload exactly as received. */
    public function __construct(
        /** Cargoboard's shipment reference, i.e. `OrderResult::$reference`. */
        public readonly ?string $reference = null,
        /** Cargoboard's order id (CUID). */
        public readonly ?string $orderId = null,
        public readonly ?string $customerId = null,
        /** Your own reference, as sent in `customerOrderCode`. */
        public readonly ?string $customerOrderCode = null,
        public readonly ?string $eventId = null,
        /** Numeric status code of the tracking event. */
        public readonly ?string $statusCode = null,
        /** Human-readable description of the event. */
        public readonly ?string $label = null,
        public readonly ?\DateTimeImmutable $originatedAt = null,
        public readonly ?string $emittedBy = null,
        public readonly ?string $nameOfSigner = null,
        public readonly ?string $message = null,
        public readonly ?\DateTimeImmutable $estimatedCollectionAtFrom = null,
        public readonly ?\DateTimeImmutable $estimatedCollectionAtUntil = null,
        public readonly ?\DateTimeImmutable $estimatedDeliveryAtFrom = null,
        public readonly ?\DateTimeImmutable $estimatedDeliveryAtUntil = null,
        /** Which environment produced the event: "production" or the test system. */
        public readonly ?string $environment = null,
        /** The payload exactly as received, for logging or for fields added later. */
        public readonly array $raw = [],
    ) {
    }

    /**
     * Parse a raw webhook body.
     *
     * @throws CargoboardResponseParseException when the body is not a JSON object.
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw (new CargoboardResponseParseException(
                'Cargoboard webhook payload is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            ))->withRawResponse($json);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw (new CargoboardResponseParseException(
                'Cargoboard webhook payload is not a JSON object.'
            ))->withRawResponse($json);
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $order = Value::object($data, 'order') ?? [];
        $event = Value::object($data, 'event') ?? [];

        return new self(
            reference:                  Value::string($order, 'reference'),
            orderId:                    Value::string($order, 'id'),
            customerId:                 Value::string($order, 'customerId'),
            customerOrderCode:          Value::string($order, 'customerOrderCode'),
            eventId:                    Value::string($event, 'id'),
            statusCode:                 Value::string($event, 'statusCode'),
            label:                      Value::string($event, 'label'),
            originatedAt:               Value::dateTime($event, 'originatedAt'),
            emittedBy:                  Value::string($event, 'emittedBy'),
            nameOfSigner:               Value::string($event, 'nameOfSigner'),
            message:                    Value::string($event, 'message'),
            estimatedCollectionAtFrom:  Value::dateTime($event, 'estimatedCollectionAtFrom'),
            estimatedCollectionAtUntil: Value::dateTime($event, 'estimatedCollectionAtUntil'),
            estimatedDeliveryAtFrom:    Value::dateTime($event, 'estimatedDeliveryAtFrom'),
            estimatedDeliveryAtUntil:   Value::dateTime($event, 'estimatedDeliveryAtUntil'),
            environment:                Value::string($data, 'environment'),
            raw:                        $data,
        );
    }

    /** True when the event came from the live system rather than the sandbox. */
    public function isProduction(): bool
    {
        return strtolower($this->environment ?? '') === 'production';
    }

    /** True when the event carries a signature, i.e. it is a proof of delivery. */
    public function isProofOfDelivery(): bool
    {
        return $this->nameOfSigner !== null && $this->nameOfSigner !== '';
    }

    /** True when the event refines the estimated delivery window. */
    public function hasDeliveryEstimate(): bool
    {
        return $this->estimatedDeliveryAtFrom !== null || $this->estimatedDeliveryAtUntil !== null;
    }

    /** The collection window as this event estimates it, or null when it carries none. */
    public function pickupWindow(): ?TrackingWindow
    {
        return TrackingWindow::of($this->estimatedCollectionAtFrom, $this->estimatedCollectionAtUntil);
    }

    /** The delivery window as this event estimates it, or null when it carries none. */
    public function deliveryWindow(): ?TrackingWindow
    {
        return TrackingWindow::of($this->estimatedDeliveryAtFrom, $this->estimatedDeliveryAtUntil);
    }

    /** True when this event refines either window - i.e. it says something even if `message` is null. */
    public function hasEstimates(): bool
    {
        return $this->pickupWindow() !== null || $this->deliveryWindow() !== null;
    }

    /**
     * A line fit to show a customer: the text Cargoboard sent, else the signature it carries,
     * else the estimates it carries, else `Status {code}`.
     *
     * Identical in behaviour to {@see \VeryCodeCom\Cargoboard\Dto\TrackingEvent::describe()},
     * so a webhook-driven history and a polled one read the same. A webhook payload usually
     * does carry `label`, which is the branch a polled event never gets.
     */
    public function describe(?\DateTimeZone $timezone = null): string
    {
        return EventNarrative::describe(
            $this->label,
            $this->message,
            $this->statusCode,
            $this->pickupWindow(),
            $this->deliveryWindow(),
            $timezone,
            $this->nameOfSigner,
        );
    }
}
