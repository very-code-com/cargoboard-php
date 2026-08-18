<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown on HTTP 202 from `GET /v1/dangerous-goods/un-numbers/{unNumber}`.
 *
 * The endpoint is documented as returning 200/403/404/422 only, but the live API answers a
 * lookup for a UN number it has not cached yet with a 202 and a body of
 *
 *   {"statusCode":202,"code":"DANGEROUS_GOODS_UN_NUMBER_SYNC_QUEUED",
 *    "message":"Dangerous goods data for UN number 0001 is being synchronized. Retry later."}
 *
 * 202 is a success status, so without this check the body would be handed to
 * {@see \VeryCodeCom\Cargoboard\Dto\AdrData::fromArray()}, whose lenient parsing turns it into
 * an ADR record with an empty UN number and every field null. That is the dangerous failure
 * mode for this particular endpoint: a caller feeding the result into a shipment declaration
 * would file a dangerous good with no hazard class, no packaging group and no tunnel
 * restriction, and nothing would look wrong. Hence a distinct exception rather than a hollow
 * object.
 *
 * The condition is temporary: Cargoboard queues the sync on the first request, so retrying the
 * same UN number after a short wait normally yields the real data.
 */
class CargoboardAdrSyncPendingException extends CargoboardApiException
{
    /** Cargoboard's machine-readable code for this condition. */
    public const CODE = 'DANGEROUS_GOODS_UN_NUMBER_SYNC_QUEUED';

    /** The UN number whose data is still being synchronised, as it was requested. */
    public readonly string $unNumber;

    /**
     * @param string $unNumber UN number the lookup was for.
     * @param string $message  Cargoboard's own explanation, used verbatim when it sent one.
     */
    public function __construct(string $unNumber, string $message = '', ?\Throwable $previous = null)
    {
        $this->unNumber = $unNumber;

        parent::__construct(
            $message !== ''
                ? $message
                : sprintf('Cargoboard is still synchronising ADR data for UN number %s. Retry later.', $unNumber),
            202,
            [self::CODE],
            'Accepted',
            $previous,
        );
    }
}
