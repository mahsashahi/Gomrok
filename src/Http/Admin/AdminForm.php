<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Type-safe reads over a parsed admin form POST body (Phase 27 Increment B).
 * `getParsedBody()` returns `mixed` values; every write action needs the
 * same "is it actually a string / checked box / positive integer" guards,
 * so they live here once instead of repeated per action.
 */
final class AdminForm
{
    /**
     * @return array<array-key, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return \is_array($body) ? $body : [];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function str(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) ? trim($value) : $default;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function nullableStr(array $body, string $key): ?string
    {
        $value = self::str($body, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function nullableInt(array $body, string $key): ?int
    {
        $value = self::str($body, $key);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function checked(array $body, string $key): bool
    {
        return ($body[$key] ?? null) === 'on';
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<string>
     */
    public static function strArray(array $body, string $key): array
    {
        $value = $body[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $v): string => \is_string($v) ? trim($v) : '',
            $value,
        ), static fn (string $v): bool => $v !== ''));
    }

    private function __construct()
    {
    }
}
