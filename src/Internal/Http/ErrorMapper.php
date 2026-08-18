<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Internal\Http;

use VeryCodeCom\Cargoboard\Exception\CargoboardApiException;
use VeryCodeCom\Cargoboard\Exception\CargoboardAuthException;
use VeryCodeCom\Cargoboard\Exception\CargoboardConflictException;
use VeryCodeCom\Cargoboard\Exception\CargoboardNotFoundException;
use VeryCodeCom\Cargoboard\Exception\CargoboardRateLimitException;
use VeryCodeCom\Cargoboard\Exception\CargoboardServerException;
use VeryCodeCom\Cargoboard\Exception\CargoboardUnprocessableEntityException;
use VeryCodeCom\Cargoboard\Internal\Json\Value;
use VeryCodeCom\Cargoboard\Transport\TransportResponse;

/**
 * Turns a non-2xx Cargoboard response into the narrowest exception that fits it.
 *
 * Cargoboard runs on NestJS, so error bodies look like
 * `{"statusCode": 422, "message": [...], "error": "Unprocessable Entity"}`, with `message`
 * being a string for most statuses and an array of field messages for 422. Both shapes are
 * flattened into a list here, so callers always get {@see CargoboardApiException::$errors}.
 *
 * When a body cannot be decoded at all (an HTML error page from a proxy, an empty 502), the
 * status code alone still decides the exception type, and the message falls back to the reason
 * phrase - a caller should never have to parse HTML to find out it was rate limited.
 *
 * @internal
 */
final class ErrorMapper
{
    /** Builds the exception for a failed response, without throwing it. */
    public function map(TransportResponse $response, string $operation): CargoboardApiException
    {
        $status = $response->statusCode;

        [$messages, $error] = $this->extract($response->body);

        $detail = $messages !== [] ? implode('; ', $messages) : ($error !== '' ? $error : $this->reasonPhrase($status));
        $message = sprintf('Cargoboard %s failed with HTTP %d: %s', $operation, $status, $detail);

        $exception = match (true) {
            $status === 401, $status === 403 => new CargoboardAuthException(
                $this->authMessage($status, $message),
                $status,
                $messages,
                $error,
            ),
            $status === 404 => new CargoboardNotFoundException($message, $status, $messages, $error),
            $status === 409 => new CargoboardConflictException($message, $status, $messages, $error),
            $status === 422 => new CargoboardUnprocessableEntityException($message, $status, $messages, $error),
            $status === 429 => (new CargoboardRateLimitException($message, $status, $messages, $error))
                ->withRetryAfter($response->retryAfterSeconds()),
            $status >= 500 => new CargoboardServerException($message, $status, $messages, $error),
            default => new CargoboardApiException($message, $status, $messages, $error),
        };

        return $exception;
    }

    /**
     * Flatten a NestJS error body into a list of messages plus its short `error` label.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function extract(string $body): array
    {
        if (trim($body) === '') {
            return [[], ''];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            // Not a JSON object: an HTML error page or a bare string. Keep a short excerpt so
            // the message says something, without pasting a whole page into an exception.
            $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

            return [$excerpt !== '' ? [mb_substr($excerpt, 0, 200)] : [], ''];
        }

        /** @var array<string, mixed> $decoded */
        $messages = Value::stringList($decoded, 'message');

        return [$messages, Value::string($decoded, 'error') ?? ''];
    }

    /**
     * 401 and 403 are the errors integrators hit first, and Cargoboard's own body ("Forbidden
     * resource") says nothing actionable, so the hint about per-environment keys is appended
     * here rather than left to the README.
     */
    private function authMessage(int $status, string $message): string
    {
        if ($status === 401) {
            return $message . ' No X-API-KEY header was sent.';
        }

        return $message . ' The API key was rejected: check that it is activated for API access'
            . ' and that it belongs to the environment you are calling (sandbox and production keys are not interchangeable).';
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request (malformed JSON body)',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'HTTP error',
        };
    }
}
