<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Tests\Support;

use VeryCodeCom\Cargoboard\Dto\Address;
use VeryCodeCom\Cargoboard\Dto\Consignee;
use VeryCodeCom\Cargoboard\Dto\Line;
use VeryCodeCom\Cargoboard\Dto\ShipmentRequest;
use VeryCodeCom\Cargoboard\Dto\Shipper;
use VeryCodeCom\Cargoboard\Enum\CountryCode;
use VeryCodeCom\Cargoboard\Enum\PackageType;
use VeryCodeCom\Cargoboard\Enum\Product;

/**
 * Builders for the shipment fixtures the tests share.
 *
 * All dates are pinned to a Thursday in the future so that the weekday rules in
 * {@see \VeryCodeCom\Cargoboard\Internal\Validator\ShipmentValidator} pass by construction and
 * a test that cares about them has to opt in explicitly.
 */
final class ShipmentFactory
{
    /** A Thursday, so pickup-day validation is satisfied. */
    public const PICKUP_DATE = '2026-08-20';

    /** The Friday after {@see self::PICKUP_DATE}. */
    public const DELIVERY_DATE = '2026-08-21';

    /** A Saturday, for the tests that assert weekend pickups are rejected. */
    public const WEEKEND_DATE = '2026-08-22';

    /** A complete, bookable domestic shipment. */
    public static function bookable(): ShipmentRequest
    {
        return new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupOn: self::PICKUP_DATE,
            ),
            consignee: new Consignee(
                address: new Address('85137', CountryCode::DE, 'Walting', 'Hofstetterstr. 4'),
                name: 'Fabian Müller',
            ),
            lines: [self::palletLine()],
        );
    }

    /** The minimum a quotation needs: post code and country on both sides. */
    public static function quotable(): ShipmentRequest
    {
        return new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('10115', CountryCode::DE, 'Berlin'),
                pickupOn: self::PICKUP_DATE,
            ),
            consignee: new Consignee(address: new Address('33100', CountryCode::DE, 'Paderborn')),
            lines: [self::palletLine()],
        );
    }

    /** One euro pallet, 200 kg. */
    public static function palletLine(): Line
    {
        return new Line(
            content: 'Werkzeugmaschine',
            unitQuantity: 1,
            unitPackageType: PackageType::EuroPallet,
            unitLength: 120,
            unitWidth: 80,
            unitHeight: 120,
            unitWeight: 200.0,
        );
    }

    /** A parcel-shaped line that stays inside every published parcel limit. */
    public static function parcelLine(int $quantity = 1): Line
    {
        return new Line(
            content: 'Spare parts',
            unitQuantity: $quantity,
            unitPackageType: PackageType::Carton,
            unitLength: 40,
            unitWidth: 30,
            unitHeight: 20,
            unitWeight: 5.0,
        );
    }

    /** A bookable parcel shipment, DE to DE. */
    public static function parcel(int $quantity = 2): ShipmentRequest
    {
        return new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('40239', CountryCode::DE, 'Düsseldorf', 'Examplestreet 12a'),
                name: 'Producer ABC GmbH & Co. KG',
                pickupOn: self::PICKUP_DATE,
            ),
            consignee: new Consignee(
                address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'),
                name: 'Consignee ABC AG',
            ),
            lines: [self::parcelLine($quantity)],
        );
    }
}
