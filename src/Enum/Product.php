<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * The shipment product, sent as `product` on every quotation and order.
 *
 * Cargoboard offers four base products, two of which can be narrowed down with a delivery-time
 * option (the number is the hour the delivery is guaranteed by):
 *
 *   STANDARD - cheapest, delivered within the regular lead time.
 *   EXPRESS  - prioritised groupage (24h within Germany); EXPRESS_8/10/12/16 add a delivery
 *              deadline of 08:00 / 10:00 / 12:00 / 16:00.
 *   FIX      - delivery on a specific day, set via {@see \VeryCodeCom\Cargoboard\Dto\Consignee::$deliveryOn};
 *              FIX_8/10/12/16 additionally fix the hour.
 *   DIRECT   - dedicated vehicle, no reloading; required for every {@see TruckType} except
 *              DIRECT_TRUCK_40, which ships as STANDARD or EXPRESS.
 *
 * `EXPRESS_16` and `FIX_16` exist in the API schema but are not listed on Cargoboard's
 * "Shipment Products" documentation page; they are included here to stay 1:1 with the API.
 *
 * @see https://docs.cargoboard.com/reference/choose-transport-type
 */
enum Product: string
{
    case Standard   = 'STANDARD';
    case Express    = 'EXPRESS';
    case Express8   = 'EXPRESS_8';
    case Express10  = 'EXPRESS_10';
    case Express12  = 'EXPRESS_12';
    case Express16  = 'EXPRESS_16';
    case Fix        = 'FIX';
    case Fix8       = 'FIX_8';
    case Fix10      = 'FIX_10';
    case Fix12      = 'FIX_12';
    case Fix16      = 'FIX_16';
    case Direct     = 'DIRECT';

    /** True for FIX and all of its timed variants, the only products that accept `deliveryOn`. */
    public function isFix(): bool
    {
        return match ($this) {
            self::Fix, self::Fix8, self::Fix10, self::Fix12, self::Fix16 => true,
            default => false,
        };
    }

    /** True for EXPRESS and all of its timed variants. */
    public function isExpress(): bool
    {
        return match ($this) {
            self::Express, self::Express8, self::Express10, self::Express12, self::Express16 => true,
            default => false,
        };
    }

    /**
     * The guaranteed delivery hour of a timed variant (8, 10, 12 or 16), or null for the
     * untimed products. Plain EXPRESS delivers by 16:00 but carries no explicit hour in its
     * name, so it returns null here.
     */
    public function deliveryDeadlineHour(): ?int
    {
        return match ($this) {
            self::Express8, self::Fix8   => 8,
            self::Express10, self::Fix10 => 10,
            self::Express12, self::Fix12 => 12,
            self::Express16, self::Fix16 => 16,
            default => null,
        };
    }
}
