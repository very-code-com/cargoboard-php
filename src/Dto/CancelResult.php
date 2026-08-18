<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The answer to `POST /v1/orders/{id}/cancel`.
 *
 * A deliberately thin response: Cargoboard reports a `status` and a human-readable `message`.
 * An order that can no longer be cancelled does not come back through here at all - it fails
 * with HTTP 409 and a {@see \VeryCodeCom\Cargoboard\Exception\CargoboardConflictException}.
 */
final class CancelResult
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $message = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status:  Value::string($data, 'status'),
            message: Value::string($data, 'message'),
        );
    }
}
