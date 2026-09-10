<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use Gomrok\Shared\Domain\DomainError;
use InvalidArgumentException;
use Stringable;

/**
 * A pricing group's client-scoped handle (e.g. `default`, `dach`, `global-ios`).
 * Not an ID type — the PK stays a plain `int`.
 */
final readonly class PricingGroupSlug implements Stringable
{
    private const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    private function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException("Invalid pricing group slug: \"{$value}\".");
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
            'pricing_group.invalid_slug',
            'A pricing group slug must be 1–64 lowercase letters, digits or hyphens and cannot start or end with a hyphen.',
            ['slug' => $value],
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
