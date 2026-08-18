<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tracking;

use VeryCodeCom\Cargoboard\Dto\Order;
use VeryCodeCom\Cargoboard\Dto\OrderResult;

/**
 * Builds the customer-facing tracking links, the ones you hand to your own end customers.
 *
 * Cargoboard offers two, and they differ in how access is granted:
 *
 *  - **Order-id link** ({@see self::forOrderId()}): unlocks the shipment directly, no captcha.
 *    Recommended for new integrations. `POST /v1/orders` already returns it ready-made as
 *    `platformTrackingUrl`; {@see self::forOrder()} prefers that value and falls back to
 *    building one.
 *  - **Reference link** ({@see self::forReference()}): the legacy form. The recipient has to
 *    open the page and solve a captcha before any tracking data is shown.
 *
 * Both take the consignee's post code as the access secret, so only someone who knows where the
 * shipment is going can open the page. Neither needs an extra API call: every input is already
 * in the booking response.
 *
 * Not to be confused with the `orderTrack` link in a response's `links` array, which points at
 * the tracking **API** endpoint (machine-readable JSON) rather than at a page for a human.
 *
 * @see https://docs.cargoboard.com/reference/share-a-tracking-link-with-your-customers
 */
final class TrackingUrl
{
    /** Base of the customer-facing tracking pages. */
    public const BASE_URL = 'https://my.cargoboard.com';

    private function __construct()
    {
    }

    /**
     * The recommended link: opens tracking for an order id without a captcha step.
     *
     * @param string $orderId           The order's CUID, i.e. `OrderResult::$id`.
     * @param string $consigneePostCode The post code you sent as `consignee.address.postCode`.
     */
    public static function forOrderId(string $orderId, string $consigneePostCode): string
    {
        return self::BASE_URL . '/tracking?order-id=' . rawurlencode($orderId)
            . '&consignee-post-code=' . rawurlencode($consigneePostCode);
    }

    /**
     * The legacy link: built from the shipment reference, and gated behind a captcha.
     *
     * @param string $reference         The shipment number, i.e. `OrderResult::$reference`.
     * @param string $consigneePostCode The post code you sent as `consignee.address.postCode`.
     * @param string $locale            Language segment of the page, e.g. "de" or "en".
     */
    public static function forReference(string $reference, string $consigneePostCode, string $locale = 'de'): string
    {
        return self::BASE_URL . '/' . rawurlencode($locale) . '/tracking?reference=' . rawurlencode($reference)
            . '&secret=' . rawurlencode($consigneePostCode);
    }

    /**
     * The best link available for a freshly placed order: the `platformTrackingUrl` Cargoboard
     * returned, or one built from the order id when the response did not carry it.
     */
    public static function forOrder(OrderResult $order, string $consigneePostCode): string
    {
        if ($order->platformTrackingUrl !== null && $order->platformTrackingUrl !== '') {
            return $order->platformTrackingUrl;
        }

        return self::forOrderId($order->id, $consigneePostCode);
    }

    /**
     * The direct-unlock link for a stored order, taking the consignee post code from the order
     * itself so nothing has to be passed alongside it.
     */
    public static function forStoredOrder(Order $order): string
    {
        return self::forOrderId($order->id, $order->consignee->address->postCode);
    }
}
