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
    /**
     * The next Thursday, so pickup-day validation is satisfied.
     *
     * Computed rather than pinned: Cargoboard rejects a pickup date in the past with a 422, so a
     * hard-coded date turns the integration suite red the day it goes by. The weekday is what
     * the tests actually depend on, and `next thursday` is always in the future.
     */
    public static function pickupDate(): string
    {
        return (new \DateTimeImmutable('next thursday'))->format('Y-m-d');
    }

    /** The Friday after {@see self::pickupDate()}. */
    public static function deliveryDate(): string
    {
        return (new \DateTimeImmutable('next thursday +1 day'))->format('Y-m-d');
    }

    /** The Saturday after it, for the tests that assert weekend days are rejected. */
    public static function weekendDate(): string
    {
        return (new \DateTimeImmutable('next thursday +2 days'))->format('Y-m-d');
    }

    /** A complete, bookable domestic shipment. */
    public static function bookable(): ShipmentRequest
    {
        return new ShipmentRequest(
            product: Product::Standard,
            shipper: new Shipper(
                address: new Address('58553', CountryCode::DE, 'Halver', 'Ostrstr. 24'),
                name: 'Mustermann GmbH',
                pickupOn: self::pickupDate(),
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
                pickupOn: self::pickupDate(),
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
                pickupOn: self::pickupDate(),
            ),
            consignee: new Consignee(
                address: new Address('41061', CountryCode::DE, 'Mönchengladbach', 'Examplestreet 5'),
                name: 'Consignee ABC AG',
            ),
            lines: [self::parcelLine($quantity)],
        );
    }
}
