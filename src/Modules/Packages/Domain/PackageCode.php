<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

use Gomrok\Shared\Domain\DomainError;
use InvalidArgumentException;
use Stringable;

/**
 * A package's client-scoped public identifier (e.g. `starter`, `pro_monthly`).
 * Unique per client (`UNIQUE (client_id, code)`), not an ID type — the PK stays
 * a plain `int`. Lowercase letters, digits, `-` and `_`; no leading/trailing
 * separator.
 */
final readonly class PackageCode implements Stringable
{
    private const PATTERN = '/^[a-z0-9](?:[a-z0-9_-]{0,62}[a-z0-9])?$/';

    private function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException("Invalid package code: \"{$value}\".");
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function error(string $value): DomainError
    {
        return DomainError::validation(
            'package.invalid_code',
            'A package code must be 1–64 lowercase letters, digits, hyphens or underscores and cannot start or end with a separator.',
            ['code' => $value],
        );
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
