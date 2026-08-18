<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Internal\Json;

use VeryCodeCom\Cargoboard\Exception\CargoboardResponseParseException;

/**
 * Decodes a successful Cargoboard response and unwraps its envelope.
 *
 * Every JSON endpoint answers with the same shape:
 *
 *   {"data": {...} | [...], "links": [...], "total": 123}
 *
 * so the parser's job is to decode, verify the envelope is there, and hand `data` and `links`
 * to the DTOs. Anything unexpected becomes a {@see CargoboardResponseParseException} carrying
 * the raw body, rather than a null-pointer error three layers up.
 *
 * @internal
 */
final class ResponseParser
{
    /**
     * Decode a JSON object body.
     *
     * @return array<string, mixed>
     * @throws CargoboardResponseParseException when the body is not a JSON object.
     */
    public function decode(string $body, string $operation): array
    {
        if (trim($body) === '') {
            throw (new CargoboardResponseParseException(
                "Cargoboard returned an empty body for {$operation}, expected JSON."
            ))->withRawResponse($body);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw (new CargoboardResponseParseException(
                "Cargoboard returned malformed JSON for {$operation}: {$e->getMessage()}",
                0,
                $e,
            ))->withRawResponse($body);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw (new CargoboardResponseParseException(
                "Cargoboard returned a JSON value that is not an object for {$operation}."
            ))->withRawResponse($body);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The `data` object of a single-resource response.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     * @throws CargoboardResponseParseException when `data` is missing or is not an object.
     */
    public function data(array $decoded, string $operation): array
    {
        $data = Value::object($decoded, 'data');

        if ($data === null) {
            throw (new CargoboardResponseParseException(
                "Cargoboard response for {$operation} has no \"data\" object."
            ))->withRawResponse(json_encode($decoded) ?: '');
        }

        return $data;
    }

    /**
     * The `data` object of a single-resource response, tolerating a response that *is* the
     * resource. `GET /v1/orders/{id}` and `GET /v1/quotations/{id}` are documented without a
     * response schema, so this accepts either an enveloped or a bare object rather than
     * guessing which one a given deployment sends.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    public function dataOrSelf(array $decoded): array
    {
        return Value::object($decoded, 'data') ?? $decoded;
    }
}
