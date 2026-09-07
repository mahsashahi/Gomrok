<?php

declare(strict_types=1);

namespace Gomrok\Config;

use Dotenv\Dotenv;

/**
 * Immutable application settings, resolved from environment variables.
 *
 * A `.env` file at the project root is loaded when present (local dev); in
 * CI/production the process environment is authoritative.
 */
final readonly class Settings
{
    public function __construct(
        public string $appEnv,
        public bool $appDebug,
        public DatabaseSettings $database,
    ) {
    }

    public static function fromEnvironment(string $rootDir): self
    {
        if (is_file($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->safeLoad();
        }

        return new self(
            appEnv: self::str('APP_ENV', 'production'),
            appDebug: self::bool('APP_DEBUG', false),
            database: new DatabaseSettings(
                host: self::str('DB_HOST', '127.0.0.1'),
                port: self::int('DB_PORT', 3306),
                name: self::str('DB_NAME', 'gomrok'),
                user: self::str('DB_USER', 'gomrok'),
                password: self::str('DB_PASSWORD', ''),
                charset: self::str('DB_CHARSET', 'utf8mb4'),
            ),
        );
    }

    private static function str(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    private static function int(string $key, int $default): int
    {
        $value = self::str($key, (string) $default);

        return ctype_digit($value) ? (int) $value : $default;
    }

    private static function bool(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? getenv($key);

        if (!\is_string($value)) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => $default,
        };
    }
}
