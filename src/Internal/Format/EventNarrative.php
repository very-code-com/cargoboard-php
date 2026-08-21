<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Internal\Format;

use VeryCodeCom\Cargoboard\Dto\TrackingWindow;

/**
 * Turns one tracking event into a line a human can read.
 *
 * Shared by {@see \VeryCodeCom\Cargoboard\Dto\TrackingEvent} and
 * {@see \VeryCodeCom\Cargoboard\Webhook\WebhookEvent} so that a polling integration and a
 * webhook integration render the same event the same way.
 *
 * The rule this encodes: an event with no `message` is not an empty event. Codes 540, 500, 20
 * and 809 arrive with `message: null` and carry the refined collection and delivery windows
 * instead, which is the most useful thing on the feed and is invisible to anyone who renders
 * `message` alone. The same goes for a proof of delivery, which arrives as a bare code plus a
 * `nameOfSigner`.
 *
 * What it deliberately does NOT do is translate the numeric code. There are some 450 of them,
 * published as a spreadsheet rather than in the API reference; a package that guessed at their
 * meanings would be worse than one that admits it does not know. `Status 809` is the honest
 * fallback - it just must not be a bare `809`.
 *
 * @internal
 */
final class EventNarrative
{
    /**
     * The display line for an event: whatever text the API sent, else the estimates it carries,
     * else the code.
     */
    public static function describe(
        ?string $label,
        ?string $message,
        ?string $code,
        ?TrackingWindow $pickup,
        ?TrackingWindow $delivery,
        ?\DateTimeZone $timezone = null,
        ?string $nameOfSigner = null,
    ): string {
        $text = self::trimToNull($label) ?? self::trimToNull($message);

        if ($text !== null) {
            return $text;
        }

        // A proof of delivery arrives with no message on a live account: code 700 and a
        // `nameOfSigner`, nothing else. The signature is the event, so it is worth more than
        // the number - and reading a field the API sent is not the same as guessing at a code.
        $signer = self::trimToNull($nameOfSigner);

        if ($signer !== null) {
            return 'Signed by ' . $signer;
        }

        $estimates = self::estimates($pickup, $delivery, $timezone);

        if ($estimates !== null) {
            return $estimates;
        }

        $code = self::trimToNull($code);

        return $code !== null ? 'Status ' . $code : 'Status update';
    }

    /**
     * The estimate half of the line on its own, or null when the event carries no window.
     *
     * Exposed separately because integrators who want the windows appended to a message that
     * already exists (`BO26082222 - delivery now expected ...`) need the two halves apart.
     */
    public static function estimates(
        ?TrackingWindow $pickup,
        ?TrackingWindow $delivery,
        ?\DateTimeZone $timezone = null,
    ): ?string {
        $pickupText   = $pickup?->format($timezone);
        $deliveryText = $delivery?->format($timezone);

        $pickupText   = $pickupText === '' ? null : $pickupText;
        $deliveryText = $deliveryText === '' ? null : $deliveryText;

        if ($pickupText !== null && $deliveryText !== null) {
            return 'Estimates updated: collection ' . $pickupText . ', delivery ' . $deliveryText;
        }
        if ($pickupText !== null) {
            return 'Collection estimated ' . $pickupText;
        }
        if ($deliveryText !== null) {
            return 'Delivery estimated ' . $deliveryText;
        }

        return null;
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
