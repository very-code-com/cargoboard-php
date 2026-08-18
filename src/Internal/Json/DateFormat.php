<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Internal\Json;

/**
 * Normalises the date inputs the request DTOs accept.
 *
 * Every date field in a Cargoboard request is either a plain date (`pickupOn`, `deliveryOn`) or
 * a UTC timestamp (`pickupAtFrom`, `pickupAtUntil`). Callers may pass a string in the right
 * shape already, or any `DateTimeInterface`, and get the API's shape either way.
 *
 * @internal
 */
final class DateFormat
{
    /** `YYYY-MM-DD`, the shape Cargoboard expects for date-only fields. */
    public const DATE = 'Y-m-d';

    /** `YYYY-MM-DDTHH:MM:SSZ` in UTC, the shape Cargoboard's examples use for timestamps. */
    public const DATE_TIME = 'Y-m-d\TH:i:s\Z';

    /** Normalise a date-only input; strings are passed through untouched. */
    public static function date(string|\DateTimeInterface|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        return $value->format(self::DATE);
    }

    /**
     * Normalise a timestamp input. `DateTimeInterface` values are converted to UTC first:
     * Cargoboard interprets a naive timestamp as UTC, so sending a local time unconverted
     * silently shifts a pickup window by the offset.
     */
    public static function dateTime(string|\DateTimeInterface|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::DATE_TIME);
    }

    /**
     * Parse a normalised date string back into a date object, for the local validation rules
     * that need to know which weekday a pickup falls on. Returns null when unparseable.
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
