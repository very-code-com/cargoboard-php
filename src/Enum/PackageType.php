<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * `unitPackageType` of a shipment line.
 *
 * Three groups share this one field:
 *
 *  1. Handling units (EP, FP, GB, KI, KT, PA, CC, ...) - the normal case, one line per kind of
 *     package.
 *  2. `PARTIE` - a loading-metre (Lademeter/LDM) block: a single line whose length carries the
 *     loading metres, see {@see \VeryCodeCom\Cargoboard\Dto\Line::loadingMetres()}.
 *  3. `DIRECT_*` - a whole dedicated vehicle rather than a package; see {@see TruckType}.
 *
 * Cargoboard accepts a much longer list of package-type codes on *responses* (the codes its
 * own network uses internally, e.g. `BU`, `KN`, `RE`); those are exposed as raw strings on
 * {@see \VeryCodeCom\Cargoboard\Dto\OrderLine} rather than being forced into this enum, which
 * lists only the codes documented as valid for a request.
 *
 * @see https://docs.cargoboard.com/reference/lines-packagetypes-and-content
 * @see https://docs.cargoboard.com/reference/how-to-book-lademeter-ldm
 */
enum PackageType: string
{
    /** One-way pallet: any wooden/plastic pallet that is not a EURO pallet. */
    case OneWayPallet = 'EP';
    /** EURO pallet, 120x80 cm. */
    case EuroPallet = 'FP';
    /** EURO box pallet (Gitterbox), 123x84 cm. */
    case BoxPallet = 'GB';
    /** Wooden crate. */
    case WoodenCrate = 'KI';
    /** Carton; also the package type to use for parcel shipments. */
    case Carton = 'KT';
    /** Package / parcel. */
    case Package = 'PA';
    /** Collico. */
    case Collico = 'CC';

    /** Loading-metre block (Teil-/Komplettladung booked by Lademeter). */
    case LoadingMetres = 'PARTIE';

    case DirectCar             = 'DIRECT_PKW';
    case DirectVan             = 'DIRECT_BUS';
    case DirectVanTailLift     = 'DIRECT_BUS_TL';
    case DirectCurtainVan      = 'DIRECT_BUS_PLANE';
    case DirectCurtainVanTailLift = 'DIRECT_BUS_PLANE_TL';
    case DirectSprinterXxl     = 'DIRECT_BUS_PLANE_XXL';
    case DirectSprinterXxlTailLift = 'DIRECT_BUS_PLANE_XXL_TL';
    case DirectTruck7_5        = 'DIRECT_TRUCK_7_5';
    case DirectTruck7_5TailLift = 'DIRECT_TRUCK_7_5_TL';
    case DirectTruck12         = 'DIRECT_TRUCK_12';
    case DirectTruck12TailLift = 'DIRECT_TRUCK_12_TL';
    case DirectTruck40         = 'DIRECT_TRUCK_40';

    /** True for the whole-vehicle package types (`DIRECT_*`). */
    public function isVehicle(): bool
    {
        return str_starts_with($this->value, 'DIRECT_');
    }

    /** True for the loading-metre package type. */
    public function isLoadingMetres(): bool
    {
        return $this === self::LoadingMetres;
    }

    /** The matching {@see TruckType}, or null when this is not a vehicle package type. */
    public function truckType(): ?TruckType
    {
        return TruckType::tryFrom(str_ends_with($this->value, '_TL') ? substr($this->value, 0, -3) : $this->value);
    }
}
