<?php

declare(strict_types=1);

namespace VeryCodeCom\Cargoboard\Dto;

use VeryCodeCom\Cargoboard\Internal\Json\Value;

/**
 * The local contact at the pickup or delivery site.
 *
 * Optional everywhere, but it is what makes the "wants..." advice services work: a driver who
 * has to announce arrival by phone needs a number to call, and Cargoboard's dispatchers use the
 * e-mail address for delivery notifications.
 */
final class ContactPerson
{
    public function __construct(
        public readonly ?string $name = null,
        /** In international format, e.g. "+4917287502631". */
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'name'  => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
        ], static fn (?string $v): bool => $v !== null && $v !== '');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name:  Value::string($data, 'name'),
            phone: Value::string($data, 'phone'),
            email: Value::string($data, 'email'),
        );
    }

    /** True when at least one way of reaching this person is filled in. */
    public function isReachable(): bool
    {
        return ($this->phone !== null && $this->phone !== '') || ($this->email !== null && $this->email !== '');
    }
}
