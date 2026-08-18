<?php

/**
 * Example 03. Track a shipment, and hand your customer a tracking link.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - the two views a tracking response contains: milestones and raw events
 *   - reading the estimated delivery window as it gets refined en route
 *   - the difference between the two customer-facing tracking links
 *
 * Run:
 *   CARGOBOARD_API_KEY=xxx php examples/03_tracking.php 10374504
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\Enum\TrackingStepStatus;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;
use VeryCodeCom\Cargoboard\Tracking\TrackingUrl;

$client = CargoboardClient::sandbox(getenv('CARGOBOARD_API_KEY') ?: 'your-api-key');

// Cargoboard accepts either the order CUID or the shipment reference here.
$reference = $argv[1] ?? '10374504';
$consigneePostCode = $argv[2] ?? '41061';

try {
    $tracking = $client->fetchTracking($reference);

    // -----------------------------------------------------------------------
    // 1. The milestone chain: the progress-bar view. Every milestone is
    //    returned, reached or not, so this renders without any interpretation
    //    of raw status codes.
    // -----------------------------------------------------------------------
    echo "Milestones\n";
    foreach ($tracking->steps as $step) {
        $marker = match ($step->status) {
            TrackingStepStatus::Success => '[x]',
            TrackingStepStatus::Warning => '[!]',
            default                     => '[ ]',
        };

        printf(
            "  %s %-24s %s\n",
            $marker,
            $step->type?->value ?? 'UNKNOWN',
            $step->originatedAt?->format('Y-m-d H:i') ?? '',
        );
    }

    $current = $tracking->currentStep();
    echo "\n  Current : " . ($current?->type?->value ?? 'not started') . "\n";
    echo "  Delivered: " . ($tracking->isDelivered() ? 'yes' : 'no') . "\n";

    if ($tracking->hasWarning()) {
        echo "  ATTENTION: at least one milestone came back as a warning.\n";
    }

    if ($tracking->signedBy() !== null) {
        echo "  Signed by: {$tracking->signedBy()}\n";
    }

    // -----------------------------------------------------------------------
    // 2. The raw event feed: everything the network reported, including the
    //    estimates that a customer-facing UI wants.
    // -----------------------------------------------------------------------
    echo "\nEvent history\n";
    foreach ($tracking->events as $event) {
        printf(
            "  %-17s %-5s %s\n",
            $event->originatedAt?->format('Y-m-d H:i') ?? '',
            $event->code ?? '',
            $event->label ?? $event->message ?? '',
        );

        if ($event->location !== null) {
            printf("      at %s %s\n", $event->location->depotId ?? '', $event->location->name ?? '');
        }
        if ($event->hasVehiclePosition()) {
            printf("      vehicle at %.4f, %.4f\n", (float) $event->vehicleLatitude, (float) $event->vehicleLongitude);
        }
        if ($event->stopsUntilDelivery !== null) {
            printf("      %d stop(s) until delivery\n", (int) $event->stopsUntilDelivery);
        }
    }

    $estimate = $tracking->estimatedDelivery();
    if ($estimate !== null) {
        printf(
            "\nEstimated delivery: %s - %s\n",
            $estimate['from']?->format('Y-m-d H:i') ?? '?',
            $estimate['until']?->format('Y-m-d H:i') ?? '?',
        );
    }

    // -----------------------------------------------------------------------
    // 3. Links for the end customer. The order-id link unlocks the shipment
    //    directly; the reference link makes them solve a captcha first.
    // -----------------------------------------------------------------------
    $order = $client->fetchOrder($reference);

    echo "\nCustomer tracking links\n";
    echo "  Recommended (no captcha): " . TrackingUrl::forStoredOrder($order) . "\n";
    echo "  Legacy (captcha)        : " . TrackingUrl::forReference($order->reference, $consigneePostCode) . "\n";
    echo "\nFor push updates instead of polling, ask api@cargoboard.com to register a\n";
    echo "Track & Trace webhook and parse it with Webhook\\WebhookEvent (see example 10).\n";
} catch (CargoboardNotFoundException) {
    echo "No shipment found for \"{$reference}\" on this account.\n";
} catch (CargoboardApiException $e) {
    echo "Cargoboard API error (HTTP {$e->statusCode}): {$e->getMessage()}\n";
}
