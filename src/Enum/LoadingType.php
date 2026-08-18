<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `shipper.loadingType` / `consignee.unloadingType`: how the vehicle is loaded or unloaded.
 *
 * Note that `LIFTING_PLATFORM_OR_TAIL_LIFT_TRUCK` only describes the *method*; booking the tail
 * lift itself additionally requires `wantsTailLiftTruck: true` on the same party.
 *
 * @see https://docs.cargoboard.com/reference/vantruck-product
 */
enum LoadingType: string
{
    /** Loaded/unloaded via a ramp. */
    case Ramp = 'RAMP';
    /** Loaded/unloaded from the side. */
    case Side = 'SIDE';
    /** Open roof, loaded/unloaded from the top by crane. */
    case Crane = 'CRANE';
    /** Loaded/unloaded via a lifting platform or tail lift. */
    case LiftingPlatformOrTailLiftTruck = 'LIFTING_PLATFORM_OR_TAIL_LIFT_TRUCK';
}
