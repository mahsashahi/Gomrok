<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * An ISO 3166-1 alpha-2 country code (format-validated here; membership against
 * the real list is enforced by the `countries` reference table from Phase 4).
 */
final readonly class CountryCode implements Stringable
{
    private function __construct(public string $value)
    {
    }

    public static function of(string $code): self
    {
        $normalised = strtoupper(trim($code));

        if (preg_match('/^[A-Z]{2}$/', $normalised) !== 1) {
            throw new InvalidArgumentException("Not an ISO 3166-1 alpha-2 country code: {$code}");
        }

        return new self($normalised);
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
