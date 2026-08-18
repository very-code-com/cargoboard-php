<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Exception;

/**
 * Thrown when Cargoboard answers with an HTTP error status.
 *
 * Cargoboard's error bodies are NestJS-shaped:
 *
 *   {"statusCode": 422, "message": ["shipper.address.countryCode must be one of ..."], "error": "Unprocessable Entity"}
 *
 * `message` is a string for most errors and an array of per-field messages for 422, so it is
 * normalised into {@see self::$errors} either way.
 *
 * The client throws the narrowest subclass it can (auth, not found, conflict, unprocessable
 * entity, rate limit, server error); this class is the fallback for any other status.
 */
class CargoboardApiException extends CargoboardException
{
    /**
     * @param int          $statusCode HTTP status code Cargoboard returned.
     * @param list<string> $errors     Flattened `message` field, one entry per reported problem.
     * @param string       $error      Cargoboard's short `error` label, e.g. "Unprocessable Entity".
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $errors = [],
        public readonly string $error = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /** True when at least one reported message contains the given substring (case-insensitive). */
    public function hasError(string $needle): bool
    {
        foreach ($this->errors as $error) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The field names Cargoboard complained about, parsed out of its 422 messages
     * (`"shipper.address.countryCode must be one of ..."` -> `shipper.address.countryCode`).
     *
     * @return list<string>
     */
    public function getFieldNames(): array
    {
        $fields = [];

        foreach ($this->errors as $error) {
            if (preg_match('/^([A-Za-z0-9_.\[\]]+)\s/', $error, $m) === 1) {
                $fields[] = $m[1];
            }
        }

        return array_values(array_unique($fields));
    }
}
