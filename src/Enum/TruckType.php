<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `truckType` as reported on a quotation/order response for whole-vehicle bookings.
 *
 * The same values (plus `_TL` tail-lift variants and the smaller `DIRECT_PKW`/`DIRECT_BUS`) are
 * sent on the request side as {@see PackageType}. Each vehicle has a documented payload ceiling,
 * enforced locally by {@see \VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator}.
 *
 * @see https://docs.cargoboard.com/reference/vantruck-product
 */
enum TruckType: string
{
    /** Curtain-side van, up to 1000 kg. */
    case CurtainVan = 'DIRECT_BUS_PLANE';
    /** Tarpaulin Sprinter XXL, up to 1000 kg. */
    case SprinterXxl = 'DIRECT_BUS_PLANE_XXL';
    /** Truck 7.5 t, up to 2500 kg. */
    case Truck7_5 = 'DIRECT_TRUCK_7_5';
    /** Truck 12 t, up to 5000 kg. */
    case Truck12 = 'DIRECT_TRUCK_12';
    /** Truck 40 t, up to 24000 kg. */
    case Truck40 = 'DIRECT_TRUCK_40';

    /** Maximum payload in kilograms, as documented on the Van/Truck product page. */
    public function maxPayloadKg(): int
    {
        return match ($this) {
            self::CurtainVan, self::SprinterXxl => 1000,
            self::Truck7_5 => 2500,
            self::Truck12  => 5000,
            self::Truck40  => 24000,
        };
    }

    /**
     * The products this vehicle may be booked with. Everything but the 40-tonne truck is a
     * DIRECT-only transport; the 40-tonne truck runs on the STANDARD or EXPRESS network.
     *
     * @return list<Product>
     */
    public function allowedProducts(): array
    {
        return $this === self::Truck40
            ? [Product::Standard, Product::Express]
            : [Product::Direct];
    }
}
