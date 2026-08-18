<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Enum;

/**
 * The 32 country codes Cargoboard serves, as accepted by `address.countryCode`.
 *
 * Sending anything else returns HTTP 422 with a message listing exactly this set, so the enum
 * turns a round-trip into a local type error. The list is identical for quotations and orders.
 *
 * Note that it is not simply "the EU": it includes CH, GB, NO, RS, TR, AL and LI, and excludes
 * CY and MT (no road connection).
 */
enum CountryCode: string
{
    case AL = 'AL';
    case AT = 'AT';
    case BE = 'BE';
    case BG = 'BG';
    case CH = 'CH';
    case CZ = 'CZ';
    case DE = 'DE';
    case DK = 'DK';
    case EE = 'EE';
    case ES = 'ES';
    case FI = 'FI';
    case FR = 'FR';
    case GB = 'GB';
    case GR = 'GR';
    case HR = 'HR';
    case HU = 'HU';
    case IE = 'IE';
    case IT = 'IT';
    case LI = 'LI';
    case LT = 'LT';
    case LU = 'LU';
    case LV = 'LV';
    case NL = 'NL';
    case NO = 'NO';
    case PL = 'PL';
    case PT = 'PT';
    case RO = 'RO';
    case RS = 'RS';
    case SE = 'SE';
    case SI = 'SI';
    case SK = 'SK';
    case TR = 'TR';

    /**
     * Build from a case-insensitive ISO 3166-1 alpha-2 string.
     *
     * @throws \InvalidArgumentException when the country is outside Cargoboard's service area.
     */
    public static function fromString(string $code): self
    {
        $case = self::tryFrom(strtoupper(trim($code)));

        if ($case === null) {
            throw new \InvalidArgumentException(sprintf(
                'CountryCode: "%s" is not served by Cargoboard. Allowed: %s.',
                $code,
                implode(', ', array_map(static fn (self $c) => $c->value, self::cases())),
            ));
        }

        return $case;
    }

    /**
     * True when the country is inside the EU customs territory. Shipments where shipper and
     * consignee do not agree on this need customs handling (`incoterm` DAP, and usually
     * `wantsExportDeclaration`).
     */
    public function isEuCustomsTerritory(): bool
    {
        return match ($this) {
            self::AL, self::CH, self::GB, self::LI, self::NO, self::RS, self::TR => false,
            default => true,
        };
    }
}
