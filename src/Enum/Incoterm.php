<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `incoterm` of a quotation or order.
 *
 * Cargoboard's guidance: use `STANDARD` for everything inside the EU, and one of the DAP
 * variants when shipping to Switzerland or the UK, i.e. across a customs border.
 */
enum Incoterm: string
{
    /** The default; used for shipments that do not cross a customs border. */
    case Standard = 'STANDARD';
    /** Delivered at place, customs already cleared. */
    case DapCleared = 'DAP_CLEARED';
    /** Delivered at place, customs not yet cleared. */
    case DapUncleared = 'DAP_UNCLEARED';

    /** True for the two DAP variants, i.e. the customs-relevant terms. */
    public function isDap(): bool
    {
        return $this !== self::Standard;
    }
}
