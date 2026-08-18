<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `transportType`: how Cargoboard classified the shipment after pricing it.
 *
 * This is a response-only field, derived from the goods (weight, volume, loading metres) rather
 * than chosen by the caller: a `STANDARD` booking becomes GROUPAGE, PART_LOAD or DIRECT
 * depending on how much of a truck it fills.
 */
enum TransportType: string
{
    /** Consolidated general cargo, the standard groupage network. */
    case Groupage = 'GROUPAGE';
    /** Part load (Teilladung), typically loading-metre bookings. */
    case PartLoad = 'PART_LOAD';
    /** Dedicated vehicle, no reloading. */
    case Direct = 'DIRECT';
}
