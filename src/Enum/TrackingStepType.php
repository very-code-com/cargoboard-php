<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `type` of a tracking step: the seven milestones every shipment passes through.
 *
 * A tracking response returns the full set of steps, each carrying its own
 * {@see TrackingStepStatus}, so the milestones already reached and the ones still pending are
 * both visible. This is the progress-bar view; the raw event feed is `statusEventHistory`.
 */
enum TrackingStepType: string
{
    case Accepted           = 'ACCEPTED';
    case Disposition        = 'DISPOSITION';
    case PickedUp           = 'PICKED_UP';
    case Dispatched         = 'DISPATCHED';
    case Unloaded           = 'UNLOADED';
    case OnAWayToConsignee  = 'ON_A_WAY_TO_CONSIGNEE';
    case Delivered          = 'DELIVERED';

    /** Position of this milestone in the chain, starting at 1. */
    public function order(): int
    {
        return match ($this) {
            self::Accepted          => 1,
            self::Disposition       => 2,
            self::PickedUp          => 3,
            self::Dispatched        => 4,
            self::Unloaded          => 5,
            self::OnAWayToConsignee => 6,
            self::Delivered         => 7,
        };
    }
}
