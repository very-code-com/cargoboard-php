<?php

/**
 * Example 04. Browse orders and invoices.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - ListQuery: page size, filters, sorting, and asking for a total
 *   - cursor pagination (the cursor is the previous page's last `sequence`)
 *   - what a stored order carries that a booking response does not: barcodes,
 *     shipment status, network partners, settled prices, invoices
 *   - downloading an invoice PDF
 *
 * Run:
 *   CARGOBOARD_API_KEY=xxx php examples/04_orders_and_invoices.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\Cargoboard\CargoboardClient;
use VeryCodeCom\Cargoboard\Enum\FilterOperator;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Query\ListQuery;

$client = CargoboardClient::sandbox(getenv('CARGOBOARD_API_KEY') ?: 'your-api-key');

try {
    // -----------------------------------------------------------------------
    // 1. A page of orders, newest first, with the total row count.
    // -----------------------------------------------------------------------
    $page = $client->listOrders(
        ListQuery::create()
            ->take(10)
            ->orderByDesc('sequence')
            ->withTotal()
    );

    printf("Orders: %d on this page, %s in total\n\n", count($page), $page->total ?? '?');

    foreach ($page as $order) {
        printf(
            "  %-10s %-12s %-12s %8s  %s -> %s\n",
            $order->reference,
            $order->status?->value ?? '-',
            $order->shipmentStatus?->value ?? '-',
            $order->price() !== null ? (string) $order->price() : '-',
            $order->shipper->address->postCode,
            $order->consignee->address->postCode,
        );

        if ($order->barcodes() !== []) {
            echo '      barcodes: ' . implode(', ', $order->barcodes()) . "\n";
        }
        if ($order->shippingPartner !== null) {
            printf("      partners: %s -> %s\n", $order->shippingPartner, $order->deliveringPartner ?? '?');
        }
        if ($order->actualPrice !== null && $order->priceAmount !== null && abs($order->actualPrice - $order->priceAmount) > 0.001) {
            printf("      NOTE: settled at %.2f, booked at %.2f\n", $order->actualPrice, $order->priceAmount);
        }
    }

    // -----------------------------------------------------------------------
    // 2. Cursor pagination. Cargoboard pages by the `sequence` of the last row
    //    rather than by an offset, so walking the list is a loop on nextCursor().
    // -----------------------------------------------------------------------
    $cursor = $page->nextCursor();
    if ($cursor !== null) {
        $next = $client->listOrders(ListQuery::create()->take(10)->cursor($cursor));
        printf("\nNext page: %d more order(s)\n", count($next));
    }

    // -----------------------------------------------------------------------
    // 3. Filtering. Filters are `field="value"` expressions; several of them
    //    combine with AND by default, or with OR on request.
    // -----------------------------------------------------------------------
    $delivered = $client->listOrders(
        ListQuery::create()
            ->take(5)
            ->whereEquals('shipmentStatus', 'DELIVERED')
            ->operator(FilterOperator::And)
    );
    printf("\nDelivered (first 5): %d\n", count($delivered));

    // -----------------------------------------------------------------------
    // 4. Invoices. This endpoint requires a page size, so the client always
    //    sends one; overdue() is a local convenience over the returned page.
    // -----------------------------------------------------------------------
    $invoices = $client->listInvoices(ListQuery::create()->take(10)->withTotal());

    printf("\nInvoices: %d on this page, %s in total\n", count($invoices), $invoices->total ?? '?');

    foreach ($invoices as $invoice) {
        printf(
            "  %-16s %10.2f %s  due %s  %s\n",
            $invoice->documentNumber ?? '-',
            $invoice->documentAmount ?? 0.0,
            $invoice->paymentCurrency,
            $invoice->dueDate?->format('Y-m-d') ?? '-',
            $invoice->isPaid === true ? 'paid' : ($invoice->isOverdue() ? 'OVERDUE' : 'open'),
        );
    }

    $overdue = $invoices->overdue();
    if ($overdue !== []) {
        printf("\n%d invoice(s) overdue.\n", count($overdue));
    }

    // -----------------------------------------------------------------------
    // 5. The PDF of the first invoice, addressed by its Easybill document id.
    // -----------------------------------------------------------------------
    $first = $invoices->first();
    if ($first !== null && $first->pdfId() !== null) {
        $pdf = $client->fetchInvoicePdf($first->pdfId());
        file_put_contents(__DIR__ . '/invoice.pdf', $pdf);
        printf("\nSaved invoice %s to examples/invoice.pdf (%d bytes)\n", $first->documentNumber ?? '', strlen($pdf));
    }
} catch (CargoboardApiException $e) {
    echo "Cargoboard API error (HTTP {$e->statusCode}): {$e->getMessage()}\n";
}
