<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

use Gomrok\Shared\Domain\DomainError;

/**
 * A client's immutable public handle. Lowercase alphanumerics and single
 * hyphens, 2–64 chars (a 1-char slug is also allowed). Not an ID type — the
 * primary key stays a plain `int`; this just validates the human identifier.
 */
final readonly class ClientSlug
{
    private const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    private function __construct(public string $value)
    {
    }

    /**
     * @throws \Gomrok\Modules\Clients\Domain\InvalidClientSlug
     */
    public static function of(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidClientSlug($value);
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function error(string $value): DomainError
    {
        return DomainError::validation(
            'client.invalid_slug',
            'A client slug must be 1–64 lowercase letters, digits or hyphens and cannot start or end with a hyphen.',
            ['slug' => $value],
        );
    }
}
