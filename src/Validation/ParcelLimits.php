<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Validation;

/**
 * The published limits of Cargoboard's parcel product, in one place.
 *
 * A shipment that exceeds any of these has to be booked as freight instead. They are constants
 * rather than configuration because they come from Cargoboard's parcel documentation, not from
 * the account.
 *
 * @see https://docs.cargoboard.com/reference/book-parcel-shipments
 */
final class ParcelLimits
{
    /** Maximum weight per parcel, physical and volumetric alike, in kilograms. */
    public const MAX_WEIGHT_KG = 32.0;

    /** Maximum length of the longest side, in centimetres. */
    public const MAX_LONGEST_SIDE_CM = 100;

    /** Maximum length of the second-longest side, in centimetres. */
    public const MAX_SECOND_SIDE_CM = 76;

    /** Maximum girth (longest + 2x each remaining side), in centimetres. */
    public const MAX_GIRTH_CM = 300;

    /** Divisor of the volumetric weight formula: (L x W x H in cm) / 5000. */
    public const VOLUMETRIC_DIVISOR = 5000;

    /** Maximum parcels collected from one pickup location per day. */
    public const MAX_PARCELS_PER_PICKUP_PER_DAY = 20;

    /** Maximum insurable goods value for a parcel shipment, in EUR. */
    public const MAX_INSURED_VALUE_EUR = 40000.0;

    /** Weight in kilograms above which a Romanian shipment needs a UIT code. */
    public const ROMANIA_UIT_EXEMPT_BELOW_KG = 31.5;

    private function __construct()
    {
    }
}
