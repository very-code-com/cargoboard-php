<?php

/**
 * Example 10. A Track & Trace webhook endpoint. RUNS OFFLINE.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - parsing the payload Cargoboard POSTs on every status update
 *   - matching the event back to your own order
 *   - reacting to a delivery, a refined ETA and a proof of delivery
 *   - the authentication caveat: Cargoboard does not sign these calls
 *
 * Webhooks are registered by Cargoboard, not through the API: send your HTTPS
 * endpoint URL, any auth details and your customer number to api@cargoboard.com.
 *
 * Run (replays two sample payloads):
 *   php examples/10_webhook_endpoint.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Webhook\WebhookEvent;

// ---------------------------------------------------------------------------
// In a real endpoint this is your controller. The only Cargoboard-specific
// part is WebhookEvent::fromJson(); everything else is your own domain.
// ---------------------------------------------------------------------------
function handleWebhook(string $rawBody): string
{
    try {
        $event = WebhookEvent::fromJson($rawBody);
    } catch (CargoboardResponseParseException $e) {
        // Answer 400 so Cargoboard's delivery attempt is recorded as failed.
        return "400 Bad Request: {$e->getMessage()}";
    }

    // Sandbox and production events can reach the same endpoint; do not let a
    // test status update change a live shipment.
    if (!$event->isProduction()) {
        return '200 OK (ignored: environment=' . ($event->environment ?? 'unknown') . ')';
    }

    // Match on YOUR reference (customerOrderCode) when you have one, and fall
    // back to Cargoboard's shipment reference.
    $key = $event->customerOrderCode ?? $event->reference ?? '(unknown)';

    // describe() is the display line: the label when Cargoboard sends one, else
    // the message, else the estimates the event carries, else "Status {code}".
    // It behaves identically to TrackingEvent::describe(), so a webhook-driven
    // history and a polled one read the same.
    $lines = [
        sprintf('  order      : %s (Cargoboard %s)', $key, $event->reference ?? '-'),
        sprintf('  status     : %s %s', $event->statusCode ?? '-', $event->describe()),
        sprintf('  happened at: %s', $event->originatedAt?->format('Y-m-d H:i:s') ?? '-'),
    ];

    // Store on the event id: it is the only key that separates two notification
    // events sent in the same second (SMS and e-mail share a status code and a
    // timestamp), which is the same trap the polling API has.
    if ($event->eventId !== null) {
        $lines[] = sprintf('  event id   : %s', $event->eventId);
    }

    if ($event->deliveryWindow() !== null) {
        $lines[] = sprintf('  new ETA    : %s', $event->deliveryWindow()?->format());
    }

    if ($event->isProofOfDelivery()) {
        $lines[] = sprintf('  DELIVERED, signed by %s', $event->nameOfSigner);
        // ... mark the shipment delivered, release the invoice, notify the customer.
    }

    echo implode("\n", $lines) . "\n";

    // Always answer quickly: do the slow work in a queue, not in the request.
    return '200 OK';
}

// ---------------------------------------------------------------------------
// Two sample payloads, in the shape Cargoboard documents.
// ---------------------------------------------------------------------------
$inTransit = <<<'JSON'
{
  "order": {
    "reference": "10374504",
    "id": "cm3328igs04jbqt0dluurnxyl",
    "customerId": "3d0cbeba-13c0-480e-ba83-9f8347b57858",
    "customerOrderCode": "ORDER-20260818-01"
  },
  "event": {
    "id": "evt-1",
    "statusCode": "400",
    "label": "Sendung abgeholt",
    "originatedAt": "2026-08-18T11:41:00Z",
    "emittedBy": "0163",
    "estimatedCollectionAtFrom": "2026-08-18T07:00:00Z",
    "estimatedCollectionAtUntil": "2026-08-18T15:00:00Z",
    "estimatedDeliveryAtFrom": "2026-08-20T08:00:00Z",
    "estimatedDeliveryAtUntil": "2026-08-20T18:00:00Z"
  },
  "environment": "production"
}
JSON;

$delivered = <<<'JSON'
{
  "order": { "reference": "10374504", "id": "cm3328igs04jbqt0dluurnxyl", "customerOrderCode": "ORDER-20260818-01" },
  "event": {
    "id": "evt-2",
    "statusCode": "700",
    "label": "Sendung zugestellt",
    "originatedAt": "2026-08-20T11:05:00Z",
    "nameOfSigner": "F. Müller",
    "message": "Zustellung erfolgt"
  },
  "environment": "production"
}
JSON;

$sandboxEvent = '{"order":{"reference":"1"},"event":{"statusCode":"400"},"environment":"sandbox"}';

foreach (['in transit' => $inTransit, 'delivered' => $delivered, 'from the sandbox' => $sandboxEvent, 'broken' => '{oops'] as $label => $payload) {
    echo "Payload: {$label}\n";
    echo '  -> ' . handleWebhook($payload) . "\n\n";
}

// ---------------------------------------------------------------------------
// Security note
// ---------------------------------------------------------------------------
echo <<<'TEXT'
Authentication
--------------
Cargoboard does not document a signature or shared secret for webhook calls, so
verify them the way you agreed when the endpoint was registered:

  - put the endpoint behind a hard-to-guess path, and require a bearer token or
    basic auth that you gave Cargoboard when registering it;
  - restrict it to Cargoboard's source addresses if they give you a range;
  - treat every field as untrusted input, and never act on an event for an order
    that does not exist on your side.

Also make the handler idempotent: a status update may arrive more than once, and
`event.id` is the natural deduplication key.

TEXT;
