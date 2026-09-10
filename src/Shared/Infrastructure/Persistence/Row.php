<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

/**
 * Coerces the `mixed` values that come out of `PDOStatement::fetch()` into known
 * scalar types at the persistence boundary — so adapters stay type-clean without
 * inline `@var` or bare casts.
 */
final class Row
{
    public static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    public static function nullableStr(mixed $value): ?string
    {
        return $value === null ? null : self::str($value);
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : self::int($value);
    }

    public static function bool(mixed $value): bool
    {
        return \is_scalar($value) && (bool) $value;
    }
}
