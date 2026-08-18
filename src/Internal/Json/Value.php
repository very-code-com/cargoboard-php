<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Internal\Json;

/**
 * Type-safe readers for values pulled out of a decoded Cargoboard JSON response.
 *
 * Response DTOs are built from `array<string, mixed>`, so every field needs a cast that PHPStan
 * can follow and that survives the two things a live API does to a documented schema: sending
 * `null` where the schema promises a value, and sending a number as a string (or the other way
 * round). Every reader here returns null rather than throwing when a field is absent or
 * unusable, which keeps a single unexpected field from breaking an otherwise good response.
 *
 * @internal
 */
final class Value
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Booleans arrive as real JSON booleans, but Cargoboard's own examples also show `"true"`
     * and `1` in places, so both are accepted.
     *
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * Parse an ISO-8601 date or date-time. Cargoboard mixes both shapes in one response
     * (`"2024-12-11"` for `deliveryOn`, `"2024-12-11T07:00:00.000Z"` for `pickupOn`), so both
     * are handled here rather than at every call site.
     *
     * @param array<string, mixed> $data
     */
    public static function dateTime(array $data, string $key): ?\DateTimeImmutable
    {
        $value = self::string($data, $key);

        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            // An unparseable timestamp is not worth failing a whole response over.
            return null;
        }
    }

    /**
     * A nested object, or null when the key is absent or holds anything but an object.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A list of nested objects, skipping any entry that is not an object. Returns an empty
     * list when the key is absent, which is what callers want for an omitted collection.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item) && !array_is_list($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A list of strings, skipping non-scalar entries.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            } elseif (is_int($item) || is_float($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
    }

    /**
     * A backed enum case, or null when the value is absent or is a member Cargoboard added
     * after this library was released. Unknown values are deliberately not an error: an API
     * that grows a new status code must not break a client that only reads the ones it knows.
     *
     * @template T of \BackedEnum
     * @param array<string, mixed> $data
     * @param class-string<T> $enum
     * @return T|null
     */
    public static function enum(array $data, string $key, string $enum): ?\BackedEnum
    {
        $value = self::string($data, $key);

        return $value !== null ? $enum::tryFrom($value) : null;
    }
}
