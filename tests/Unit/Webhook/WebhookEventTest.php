<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Webhook\WebhookEvent;

final class WebhookEventTest extends TestCase
{
    /** The payload exactly as documented on Cargoboard's Track & Trace page. */
    private const PAYLOAD = <<<'JSON'
    {
      "order": {
        "reference": "10374504",
        "id": "cm3328igs04jbqt0dluurnxyl",
        "customerId": "3d0cbeba-13c0-480e-ba83-9f8347b57858",
        "customerOrderCode": "XYZ-1568788"
      },
      "event": {
        "id": "evt-1",
        "statusCode": "700",
        "label": "Sendung zugestellt",
        "originatedAt": "2025-05-02T12:00:00Z",
        "emittedBy": "0250",
        "nameOfSigner": "F. Müller",
        "message": "Zustellung erfolgt",
        "estimatedCollectionAtFrom": "2025-05-03T08:00:00Z",
        "estimatedCollectionAtUntil": "2025-05-03T12:00:00Z",
        "estimatedDeliveryAtFrom": "2025-05-05T08:00:00Z",
        "estimatedDeliveryAtUntil": "2025-05-05T18:00:00Z"
      },
      "environment": "production"
    }
    JSON;

    public function testParsesTheDocumentedPayload(): void
    {
        $event = WebhookEvent::fromJson(self::PAYLOAD);

        self::assertSame('10374504', $event->reference);
        self::assertSame('cm3328igs04jbqt0dluurnxyl', $event->orderId);
        self::assertSame('XYZ-1568788', $event->customerOrderCode);
        self::assertSame('evt-1', $event->eventId);
        self::assertSame('700', $event->statusCode);
        self::assertSame('Sendung zugestellt', $event->label);
        self::assertSame('2025-05-02 12:00', $event->originatedAt?->format('Y-m-d H:i'));
        self::assertSame('0250', $event->emittedBy);
        self::assertSame('F. Müller', $event->nameOfSigner);
        self::assertSame('2025-05-05 18:00', $event->estimatedDeliveryAtUntil?->format('Y-m-d H:i'));
    }

    public function testDistinguishesProductionFromSandboxEvents(): void
    {
        self::assertTrue(WebhookEvent::fromJson(self::PAYLOAD)->isProduction());

        $sandbox = WebhookEvent::fromArray(['environment' => 'sandbox']);
        self::assertFalse($sandbox->isProduction());

        // An event without an environment must not be mistaken for a live one.
        self::assertFalse(WebhookEvent::fromArray([])->isProduction());
    }

    public function testRecognisesAProofOfDeliveryAndADeliveryEstimate(): void
    {
        $event = WebhookEvent::fromJson(self::PAYLOAD);

        self::assertTrue($event->isProofOfDelivery());
        self::assertTrue($event->hasDeliveryEstimate());

        $pickup = WebhookEvent::fromArray(['event' => ['statusCode' => '400']]);
        self::assertFalse($pickup->isProofOfDelivery());
        self::assertFalse($pickup->hasDeliveryEstimate());
    }

    public function testKeepsTheRawPayloadForFieldsItDoesNotModel(): void
    {
        $event = WebhookEvent::fromArray(['event' => ['statusCode' => '400'], 'somethingNew' => 'value']);

        self::assertSame('value', $event->raw['somethingNew']);
    }

    public function testAPartialPayloadDoesNotBlowUp(): void
    {
        $event = WebhookEvent::fromJson('{"order":{"reference":"10374504"}}');

        self::assertSame('10374504', $event->reference);
        self::assertNull($event->statusCode);
        self::assertNull($event->originatedAt);
    }

    public function testMalformedJsonIsReportedAsAParseException(): void
    {
        $this->expectException(CargoboardResponseParseException::class);
        $this->expectExceptionMessage('not valid JSON');

        WebhookEvent::fromJson('{not json');
    }

    public function testAJsonArrayIsRejected(): void
    {
        $this->expectException(CargoboardResponseParseException::class);
        $this->expectExceptionMessage('not a JSON object');

        WebhookEvent::fromJson('[1,2,3]');
    }
}
