<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/** `status` of a tracking step: whether that milestone is done, pending or went wrong. */
enum TrackingStepStatus: string
{
    /** Milestone reached without a problem. */
    case Success  = 'SUCCESS';
    /** Milestone reached, but something needs attention (delay, damage, failed delivery). */
    case Warning  = 'WARNING';
    /** Milestone not reached yet. */
    case Pending  = 'PENDING';
    /** No status reported for this milestone. */
    case NoStatus = 'NO_STATUS';

    /** True once the step has happened, successfully or not. */
    public function isReached(): bool
    {
        return $this === self::Success || $this === self::Warning;
    }
}
