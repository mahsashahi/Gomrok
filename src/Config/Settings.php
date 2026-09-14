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
        /**
         * Base64-encoded 32-byte key for {@see \Gomrok\Shared\Infrastructure\Crypto\SodiumSecretCipher}.
         * Null when `APP_ENCRYPTION_KEY` is unset — only the cipher itself fails
         * (at construction), so code paths that don't touch provider secrets
         * still work.
         */
        public ?string $encryptionKeyBase64 = null,
        /**
         * Gomrok's own public base URL — used to build the return endpoint's
         * absolute successUrl/cancelUrl handed to provider adapters (Phase 24
         * Q2). Defaults to a local dev value.
         */
        public string $appBaseUrl = 'http://localhost:8080',
        /**
         * HMAC secret for {@see \Gomrok\Modules\Checkout\Domain\CheckoutReturnToken}
         * (Phase 24 Q4). Hardcoded default per an explicit user instruction —
         * moving it to a required environment variable is deliberately
         * deferred, not an oversight.
         */
        public string $checkoutReturnTokenSecret = 'gomrokimo',
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
            encryptionKeyBase64: self::nullableStr('APP_ENCRYPTION_KEY'),
            database: new DatabaseSettings(
                host: self::str('DB_HOST', '127.0.0.1'),
                port: self::int('DB_PORT', 3306),
                name: self::str('DB_NAME', 'gomrok'),
                user: self::str('DB_USER', 'gomrok'),
                password: self::str('DB_PASSWORD', ''),
                charset: self::str('DB_CHARSET', 'utf8mb4'),
            ),
            appBaseUrl: self::str('APP_BASE_URL', 'http://localhost:8080'),
            // Deliberately not environment-backed yet (Phase 24 Q4) — always
            // "gomrokimo" until moving it to a required env var is picked up
            // as its own task.
            checkoutReturnTokenSecret: 'gomrokimo',
        );
    }

    private static function str(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    private static function nullableStr(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return \is_string($value) && $value !== '' ? $value : null;
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
