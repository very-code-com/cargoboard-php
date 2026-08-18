<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `shipmentStatus` of an order: the coarse physical state of the goods, as opposed to the
 * booking state in {@see OrderStatus}.
 *
 * This is the summarised view; the full event history is available through
 * {@see \VeryCodeCom\Cargoboard\CargoboardClient::fetchTracking()}.
 */
enum ShipmentStatus: string
{
    case Created        = 'CREATED';
    case InDisposition  = 'IN_DISPOSITION';
    case Collected      = 'COLLECTED';
    case Transit        = 'TRANSIT';
    case Delivered      = 'DELIVERED';
    case ActionRequired = 'ACTION_REQUIRED';
    case Cancelled      = 'CANCELLED';

    /** True once the shipment has reached a state that no longer changes on its own. */
    public function isFinal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }
}
