<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * A HATEOAS link from the `links` array of a response: the follow-up calls Cargoboard offers
 * for the resource just returned.
 *
 * Known relations: `self`, `quotationBook`, `orderCancel`, `orderPrintConfirmation`,
 * `orderPrintShipmentLabels`, `orderTrack`.
 *
 * This library builds its own URLs from the resource id rather than following these links, so
 * they are informational; the one that is genuinely useful is `orderTrack`, whose href contains
 * the shipment reference rather than the order id.
 */
final class Link
{
    public function __construct(
        public readonly string $rel,
        public readonly string $method,
        public readonly string $href,
        public readonly ?string $description = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            rel:         Value::string($data, 'rel') ?? '',
            method:      strtoupper(Value::string($data, 'method') ?? 'GET'),
            href:        Value::string($data, 'href') ?? '',
            description: Value::string($data, 'description'),
        );
    }

    /**
     * Parse a `links` value. Cargoboard's schema declares it as a single object while every
     * real response sends an array, so both shapes are accepted.
     *
     * @param array<string, mixed> $data
     * @return list<self>
     */
    public static function listFromResponse(array $data): array
    {
        $links = $data['links'] ?? null;

        if (is_array($links) && !array_is_list($links)) {
            /** @var array<string, mixed> $links */
            return [self::fromArray($links)];
        }

        return array_map(
            static fn (array $l): self => self::fromArray($l),
            Value::objectList($data, 'links'),
        );
    }

    /**
     * The first link with the given relation, or null.
     *
     * @param list<self> $links
     */
    public static function findRel(array $links, string $rel): ?self
    {
        foreach ($links as $link) {
            if ($link->rel === $rel) {
                return $link;
            }
        }

        return null;
    }
}
