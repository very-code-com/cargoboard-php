<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeryCodeCom\Cargoboard\Dto\AdrData;
use VeryCodeCom\Cargoboard\Dto\CancelResult;
use VeryCodeCom\Cargoboard\Dto\InvoicePage;
use VeryCodeCom\Cargoboard\Dto\Link;
use VeryCodeCom\Cargoboard\Dto\Order;
use VeryCodeCom\Cargoboard\Dto\OrderPage;
use VeryCodeCom\Cargoboard\Dto\OrderResult;
use VeryCodeCom\Cargoboard\Dto\Quotation;
use VeryCodeCom\Cargoboard\Dto\QuotationPage;
use VeryCodeCom\Cargoboard\Dto\QuotationResult;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\TrackingResult;
use VeryCodeCom\Cargoboard\Enum\LabelFormat;
use VeryCodeCom\Cargoboard\Exception\CargoboardAdrSyncPendingException;
use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAuthException;
use VeryCodeCom\Cargoboard\Exception\CargoboardConflictException;
use VeryCodeCom\Cargoboard\Exception\CargoboardException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;
use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;
use VeryCodeCom\Cargoboard\Exception\CargoboardTransportException;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Exception\CargoboardValidationException;
use VeryCodeCom\Cargoboard\Internal\Http\ErrorMapper;
use VeryCodeCom\Cargoboard\Internal\Http\RequestBuilder;
use VeryCodeCom\Cargoboard\Internal\Json\ResponseParser;
use VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator;
use VeryCodeCom\Cargoboard\Query\ListQuery;
use VeryCodeCom\Cargoboard\Transport\CurlTransport;
use VeryCodeCom\Cargoboard\Transport\TransportInterface;
use VeryCodeCom\Cargoboard\Transport\TransportRequest;
use VeryCodeCom\Cargoboard\Transport\TransportResponse;
use VeryCodeCom\Cargoboard\Validation\ValidationMode;

/**
 * PHP client for the Cargoboard API.
 *
 * Quick start:
 *
 *   $client = CargoboardClient::sandbox('your-api-key');
 *
 *   $request = new ShipmentRequest(
 *       product: Product::Standard,
 *       shipper: new Shipper(
 *           address: Address::of('40239', 'DE', 'Düsseldorf', 'Examplestreet 12a'),
 *           name: 'Producer ABC GmbH',
 *           pickupOn: '2026-08-19',
 *       ),
 *       consignee: new Consignee(
 *           address: Address::of('41061', 'DE', 'Mönchengladbach', 'Examplestreet 5'),
 *           name: 'Consignee ABC AG',
 *       ),
 *       lines: [new Line('Werkzeugmaschine', 1, PackageType::EuroPallet, 120, 80, 120, 200.0)],
 *   );
 *
 *   $quotation = $client->quote($request);          // binding price
 *   $order     = $client->bookQuotation($quotation->id, $request);  // book at that price
 *
 *   echo $order->reference;                          // e.g. 10374504
 *   file_put_contents('labels.pdf', $client->fetchLabels($order->id));
 *
 * Every endpoint of the public API is covered:
 *
 *   quote() / listQuotations() / fetchQuotation()    -> /v1/quotations
 *   bookQuotation()                                  -> /v1/quotations/{id}/book
 *   placeOrder() / listOrders() / fetchOrder()       -> /v1/orders
 *   cancelOrder()                                    -> /v1/orders/{id}/cancel
 *   fetchLabels() / fetchConfirmation()              -> /v1/orders/{id}/print-*
 *   fetchTracking()                                  -> /v1/orders/{id}/tracking
 *   listInvoices() / fetchInvoicePdf()               -> /v1/invoices
 *   fetchAdrData()                                   -> /v1/dangerous-goods/un-numbers/{unNumber}
 *
 * Wherever an endpoint takes an `{id}`, Cargoboard accepts either the order's CUID or its
 * shipment reference, so both work here.
 *
 * Shipments are validated locally before every quotation and booking, so the documented rules
 * (mandatory booking fields, weekday pickups, vehicle payloads, parcel limits) fail fast with a
 * {@see CargoboardValidationException} instead of a round-trip and an HTTP 422.
 *
 * @see https://github.com/very-code-com/cargoboard-php
 */
final class CargoboardClient
{
    private readonly RequestBuilder $requests;
    private readonly ResponseParser $parser;
    private readonly ErrorMapper $errors;
    private readonly ShipmentValidator $validator;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CargoboardConfig $config,
        private readonly TransportInterface $transport = new CurlTransport(),
        ?LoggerInterface $logger = null,
    ) {
        $this->requests  = new RequestBuilder($config);
        $this->parser    = new ResponseParser();
        $this->errors    = new ErrorMapper();
        $this->validator = new ShipmentValidator();
        $this->logger    = $logger ?? new NullLogger();
    }

    // -----------------------------------------------------------------
    // Named constructors
    // -----------------------------------------------------------------

    /** Target the test system; bookings here never put a truck on the road. */
    public static function sandbox(string $apiKey): self
    {
        return new self(CargoboardConfig::sandbox($apiKey));
    }

    /** Target the live system; a booking here is a real, billable transport. */
    public static function production(string $apiKey): self
    {
        return new self(CargoboardConfig::production($apiKey));
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    /**
     * A copy of this client that sends quotations and orders in **parcel mode**, i.e. with the
     * `x-transport-type-parcel-is-active` header. Without it the very same payload is priced
     * and booked as a freight shipment.
     *
     *   $parcels = $client->withParcelMode();
     *   $parcels->placeOrder($request);
     *
     * Parcel mode also switches on the parcel-specific local rules: 32 kg, 100/76 cm sides,
     * 300 cm girth, 20 parcels per pickup per day, no dangerous goods, no neutral address.
     */
    public function withParcelMode(bool $enabled = true): self
    {
        return new self($this->config->withParcelMode($enabled), $this->transport, $this->logger);
    }

    /** The configuration in use, e.g. to check which environment a client points at. */
    public function config(): CargoboardConfig
    {
        return $this->config;
    }

    /** True when this client sends requests in parcel mode. */
    public function isParcelMode(): bool
    {
        return $this->config->parcelMode;
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * Run the local pre-flight rules WITHOUT making any network call.
     *
     * Useful to validate a shipment while a user is still filling in a form, or to check a
     * whole batch before booking any of it.
     *
     * @return list<string> Validation error messages; an empty array means locally valid.
     */
    public function validateLocally(
        ShipmentRequest $request,
        ValidationMode $mode = ValidationMode::Order,
        ?bool $parcelMode = null,
    ): array {
        return $this->validator->validate($request, $mode, $parcelMode ?? $this->config->parcelMode);
    }

    /**
     * Rules that do not fail a booking but should be seen: things Cargoboard accepts over the
     * API and then sorts out by hand, such as a private consignee booked without either
     * `wantsContactBeforeDelivery` or `wantsDeliveryWithoutConsigneePresence`.
     *
     * These are never thrown. Every quotation and booking logs them at warning level; call this
     * to show them next to a form, or to fail a batch on your own terms.
     *
     * @return list<string> Warning messages; an empty array means nothing to flag.
     */
    public function warningsFor(
        ShipmentRequest $request,
        ValidationMode $mode = ValidationMode::Order,
    ): array {
        return $this->validator->warnings($request, $mode);
    }

    // -----------------------------------------------------------------
    // Quotations
    // -----------------------------------------------------------------

    /**
     * Ask for a binding price (`POST /v1/quotations`).
     *
     * The result's id can be booked later at exactly this price with {@see self::bookQuotation()}.
     * Only quotation-level fields are required here: post code and country are enough on both
     * sides.
     *
     * @throws CargoboardValidationException if local validation fails.
     * @throws CargoboardAuthException       if Cargoboard rejects the API key.
     * @throws CargoboardUnprocessableEntityException if Cargoboard rejects the payload.
     * @throws CargoboardApiException        for other API errors.
     * @throws CargoboardTransportException  on network errors.
     * @throws CargoboardResponseParseException if the response is malformed.
     */
    public function quote(ShipmentRequest $request): QuotationResult
    {
        $this->guard($request, ValidationMode::Quotation);

        $this->logger->info('Cargoboard: requesting quotation', [
            'product' => $request->product->value,
            'lines'   => count($request->lines),
            'parcel'  => $this->config->parcelMode,
        ]);

        $decoded = $this->callJson('quote', $this->requests->post('quotations', $request->toArray()));

        return QuotationResult::fromArray(
            $this->parser->data($decoded, 'quote'),
            Link::listFromResponse($decoded),
        );
    }

    /**
     * List stored quotations (`GET /v1/quotations`), newest page first by cursor.
     *
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function listQuotations(?ListQuery $query = null): QuotationPage
    {
        $decoded = $this->callJson(
            'listQuotations',
            $this->requests->get('quotations', ($query ?? ListQuery::create())->toQueryParameters()),
        );

        return QuotationPage::fromArray($decoded, Link::listFromResponse($decoded));
    }

    /**
     * Fetch one quotation by id (`GET /v1/quotations/{id}`).
     *
     * @throws CargoboardNotFoundException  if no such quotation exists on this account.
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function fetchQuotation(string $id): Quotation
    {
        $decoded = $this->callJson(
            'fetchQuotation',
            $this->requests->get('quotations/' . RequestBuilder::pathSegment($id)),
        );

        return Quotation::fromArray($this->parser->dataOrSelf($decoded));
    }

    /**
     * Book a quotation (`POST /v1/quotations/{id}/book`): the shipment is created at the price
     * that quotation carries, rather than being re-priced.
     *
     * The full booking payload is still required - Cargoboard needs the names, streets and
     * pickup date that a quotation did not - so this validates against the booking rules.
     *
     * @param string $quotationId The id from {@see QuotationResult::$id}.
     *
     * @throws CargoboardValidationException if local validation fails.
     * @throws CargoboardConflictException   if the quotation can no longer be booked.
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function bookQuotation(string $quotationId, ShipmentRequest $request): OrderResult
    {
        $this->guard($request, ValidationMode::Order);

        $this->logger->info('Cargoboard: booking quotation', ['quotationId' => $quotationId]);

        $decoded = $this->callJson(
            'bookQuotation',
            $this->requests->post(
                'quotations/' . RequestBuilder::pathSegment($quotationId) . '/book',
                $request->toArray(),
            ),
        );

        return OrderResult::fromArray(
            $this->parser->data($decoded, 'bookQuotation'),
            Link::listFromResponse($decoded),
        );
    }

    // -----------------------------------------------------------------
    // Orders
    // -----------------------------------------------------------------

    /**
     * Book a shipment directly (`POST /v1/orders`), pricing and booking in one call.
     *
     * On the production system this schedules a real transport and incurs real cost. Use
     * {@see self::sandbox()} while developing.
     *
     * @throws CargoboardValidationException if local validation fails.
     * @throws CargoboardAuthException       if Cargoboard rejects the API key.
     * @throws CargoboardUnprocessableEntityException if Cargoboard rejects the payload.
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function placeOrder(ShipmentRequest $request): OrderResult
    {
        $this->guard($request, ValidationMode::Order);

        $this->logger->info('Cargoboard: placing order', [
            'product'           => $request->product->value,
            'lines'             => count($request->lines),
            'customerOrderCode' => $request->customerOrderCode,
            'environment'       => $this->config->getEnvironment(),
            'parcel'            => $this->config->parcelMode,
        ]);

        $decoded = $this->callJson('placeOrder', $this->requests->post('orders', $request->toArray()));

        return OrderResult::fromArray(
            $this->parser->data($decoded, 'placeOrder'),
            Link::listFromResponse($decoded),
        );
    }

    /**
     * List stored orders (`GET /v1/orders`).
     *
     *   $page = $client->listOrders(ListQuery::create()->take(25)->withTotal());
     *   foreach ($page as $order) { ... }
     *
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function listOrders(?ListQuery $query = null): OrderPage
    {
        $query ??= ListQuery::create();

        // `take` is mandatory here, exactly as it is on /v1/invoices: without it the API answers
        // 422 "take must be an integer number". /v1/quotations, confusingly, defaults it itself.
        if ($query->takeValue() === null) {
            $query = $query->take(ListQuery::MAX_TAKE);
        }

        $decoded = $this->callJson('listOrders', $this->requests->get('orders', $query->toQueryParameters()));

        return OrderPage::fromArray($decoded, Link::listFromResponse($decoded));
    }

    /**
     * Fetch one order (`GET /v1/orders/{id}`), by CUID or by shipment reference.
     *
     * @throws CargoboardNotFoundException  if no such order exists on this account.
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function fetchOrder(string $id): Order
    {
        $decoded = $this->callJson(
            'fetchOrder',
            $this->requests->get('orders/' . RequestBuilder::pathSegment($id)),
        );

        return Order::fromArray($this->parser->dataOrSelf($decoded));
    }

    /**
     * Cancel an order (`POST /v1/orders/{id}/cancel`), by CUID or by shipment reference.
     *
     * @throws CargoboardConflictException  if the order's current state does not allow it,
     *                                      e.g. the goods have already been collected.
     * @throws CargoboardNotFoundException  if no such order exists on this account.
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function cancelOrder(string $id): CancelResult
    {
        $this->logger->info('Cargoboard: cancelling order', ['id' => $id]);

        $decoded = $this->callJson(
            'cancelOrder',
            $this->requests->post('orders/' . RequestBuilder::pathSegment($id) . '/cancel'),
        );

        return CancelResult::fromArray($this->parser->dataOrSelf($decoded));
    }

    // -----------------------------------------------------------------
    // Documents
    // -----------------------------------------------------------------

    /**
     * Fetch the shipment labels as a PDF (`GET /v1/orders/{id}/print-shipment-labels`).
     *
     * A4 lays several labels out on one sheet for an office printer; A6 emits one label per
     * page, which is what a label printer expects.
     *
     * @return string Raw PDF bytes, ready to write to disk or stream to a browser.
     *
     * @throws CargoboardNotFoundException  if no such order exists on this account.
     * @throws CargoboardApiException|CargoboardTransportException
     */
    public function fetchLabels(string $id, LabelFormat $format = LabelFormat::A4): string
    {
        return $this->callPdf(
            'fetchLabels',
            $this->requests->getPdf(
                'orders/' . RequestBuilder::pathSegment($id) . '/print-shipment-labels',
                ['format' => $format->value],
            ),
        );
    }

    /**
     * Fetch the order confirmation as a PDF (`GET /v1/orders/{id}/print-confirmation`).
     *
     * @return string Raw PDF bytes.
     *
     * @throws CargoboardNotFoundException  if no such order exists on this account.
     * @throws CargoboardApiException|CargoboardTransportException
     */
    public function fetchConfirmation(string $id): string
    {
        return $this->callPdf(
            'fetchConfirmation',
            $this->requests->getPdf('orders/' . RequestBuilder::pathSegment($id) . '/print-confirmation'),
        );
    }

    // -----------------------------------------------------------------
    // Tracking
    // -----------------------------------------------------------------

    /**
     * Fetch tracking data (`GET /v1/orders/{id}/tracking`), by CUID or shipment reference.
     *
     * Returns both views at once: the milestone chain and the raw status event history. The
     * result carries the feed twice, on purpose:
     *
     *   $tracking->events              // raw, exactly as the API sent it
     *   $tracking->timeline()          // deduplicated on the event id, oldest first
     *   $event->describe()             // a display line, never a bare status number
     *
     * Take `events` when you need the untouched response, `timeline()` when you are about to
     * store or render it - the raw feed repeats notification events that share a timestamp.
     *
     * For push updates instead of polling, ask Cargoboard to register a Track & Trace webhook
     * and parse its payloads with {@see \VeryCodeCom\Cargoboard\Webhook\WebhookEvent}, which
     * offers the same `describe()`.
     *
     * @throws CargoboardNotFoundException  if no such order exists on this account.
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function fetchTracking(string $id): TrackingResult
    {
        $decoded = $this->callJson(
            'fetchTracking',
            $this->requests->get('orders/' . RequestBuilder::pathSegment($id) . '/tracking'),
        );

        return TrackingResult::fromArray($this->parser->dataOrSelf($decoded));
    }

    // -----------------------------------------------------------------
    // Invoices
    // -----------------------------------------------------------------

    /**
     * List invoices (`GET /v1/invoices`).
     *
     * Unlike the other list endpoints, this one requires `take`, so a page size is always sent;
     * it defaults to 50 when the query does not set one.
     *
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function listInvoices(?ListQuery $query = null): InvoicePage
    {
        $query ??= ListQuery::create();

        if ($query->takeValue() === null) {
            $query = $query->take(ListQuery::MAX_TAKE);
        }

        $decoded = $this->callJson('listInvoices', $this->requests->get('invoices', $query->toQueryParameters()));

        return InvoicePage::fromArray($decoded, Link::listFromResponse($decoded));
    }

    /**
     * Fetch an invoice PDF (`GET /v1/invoices/{id}/pdf`).
     *
     * @param string $id The Easybill document id, i.e. {@see \VeryCodeCom\Cargoboard\Dto\Invoice::pdfId()}.
     * @return string Raw PDF bytes.
     *
     * @throws CargoboardConflictException  if Cargoboard cannot produce the document.
     * @throws CargoboardApiException|CargoboardTransportException
     */
    public function fetchInvoicePdf(string $id): string
    {
        return $this->callPdf(
            'fetchInvoicePdf',
            $this->requests->getPdf('invoices/' . RequestBuilder::pathSegment($id) . '/pdf'),
        );
    }

    // -----------------------------------------------------------------
    // Dangerous goods
    // -----------------------------------------------------------------

    /**
     * Look up ADR master data for a UN number
     * (`GET /v1/dangerous-goods/un-numbers/{unNumber}`).
     *
     * Saves keeping your own dangerous-goods database: the result feeds straight into a
     * shipment declaration through {@see AdrData::toDangerousGood()}.
     *
     * @param string $unNumber UN number without the "UN" prefix, e.g. "3480".
     *
     * @throws CargoboardNotFoundException        if the UN number is unknown.
     * @throws CargoboardAdrSyncPendingException  if Cargoboard has not cached this UN number yet
     *                                            (HTTP 202) and the lookup should be retried.
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    public function fetchAdrData(string $unNumber): AdrData
    {
        $response = $this->sendJson(
            'fetchAdrData',
            $this->requests->get('dangerous-goods/un-numbers/' . RequestBuilder::pathSegment($unNumber)),
        );

        $decoded = $this->parser->decode($response->body, 'fetchAdrData');

        // 202 is a *success* status carrying no ADR record at all; see the exception's docblock
        // for why letting it through as an all-null AdrData is the worst outcome here.
        if ($response->statusCode === 202) {
            $message = $decoded['message'] ?? '';

            $this->fail(
                new CargoboardAdrSyncPendingException($unNumber, is_string($message) ? $message : ''),
                $response->body,
            );
        }

        return AdrData::fromArray($this->parser->dataOrSelf($decoded));
    }

    // -----------------------------------------------------------------
    // Internal orchestration
    // -----------------------------------------------------------------

    /** @throws CargoboardValidationException */
    private function guard(ShipmentRequest $request, ValidationMode $mode): void
    {
        $errors = $this->validator->validate($request, $mode, $this->config->parcelMode);

        if ($errors !== []) {
            $this->fail(new CargoboardValidationException($errors));
        }

        // Warnings are logged, never thrown: they flag bookings Cargoboard accepts and then
        // fixes operationally, so refusing them here would be this library overruling the API.
        foreach ($this->validator->warnings($request, $mode) as $warning) {
            $this->logger->warning('Cargoboard: ' . $warning);
        }
    }

    /**
     * Send a request that returns JSON, and unwrap it.
     *
     * @return array<string, mixed>
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    private function callJson(string $operation, TransportRequest $request): array
    {
        $response = $this->sendJson($operation, $request);

        return $this->parser->decode($response->body, $operation);
    }

    /**
     * Send a request and map any error status, without decoding the body.
     *
     * Split out of {@see self::callJson()} for the one caller that has to distinguish between
     * two *success* statuses: the ADR lookup answers 202 when it has not cached the UN number
     * yet, and 202 is indistinguishable from 200 once the body has been decoded.
     *
     * @throws CargoboardApiException|CargoboardTransportException
     */
    private function sendJson(string $operation, TransportRequest $request): TransportResponse
    {
        $response = $this->transport->send($request);

        $this->logger->debug('Cargoboard: response', [
            'operation' => $operation,
            'status'    => $response->statusCode,
            'url'       => $request->url,
        ]);

        if (!$response->isSuccess()) {
            $this->fail($this->errors->map($response, $operation), $response->body);
        }

        return $response;
    }

    /**
     * Send a request that returns a PDF stream.
     *
     * Cargoboard answers errors on these endpoints as JSON with an error status, so a non-2xx
     * response goes through the same mapper as everywhere else. A 2xx that is not a PDF is
     * reported as a parse error rather than silently written to disk as a broken file.
     *
     * @throws CargoboardApiException|CargoboardTransportException|CargoboardResponseParseException
     */
    private function callPdf(string $operation, TransportRequest $request): string
    {
        $response = $this->transport->send($request);

        $this->logger->debug('Cargoboard: response', [
            'operation' => $operation,
            'status'    => $response->statusCode,
            'url'       => $request->url,
        ]);

        if (!$response->isSuccess()) {
            $this->fail($this->errors->map($response, $operation), $response->body);
        }

        if (!$response->isPdf()) {
            $this->fail(
                new CargoboardResponseParseException(sprintf(
                    'Cargoboard returned %s for %s, expected a PDF stream.',
                    $response->contentType() !== '' ? $response->contentType() : 'an unrecognised payload',
                    $operation,
                )),
                $response->body,
            );
        }

        return $response->body;
    }

    /**
     * Attach the raw Cargoboard response to an exception and, when debugging is enabled, log a
     * full debug report (message + raw response + stack trace) before throwing. Centralises
     * error surfacing for every API method.
     */
    private function fail(CargoboardException $exception, ?string $rawResponse = null): never
    {
        if ($rawResponse !== null && $this->config->debug) {
            $exception->withRawResponse($rawResponse);
        }

        if ($this->config->debug) {
            $this->logger->error($exception->getDebugReport());
        } else {
            $this->logger->error($exception->getMessage());
        }

        throw $exception;
    }
}
