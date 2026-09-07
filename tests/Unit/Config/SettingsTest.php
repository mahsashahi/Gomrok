<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Config;

use Gomrok\Config\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'APP_DEBUG', 'DB_HOST', 'DB_PORT', 'DB_NAME'] as $key) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    #[Test]
    public function fallsBackToSafeDefaults(): void
    {
        $settings = Settings::fromEnvironment('/nonexistent');

        self::assertSame('production', $settings->appEnv);
        self::assertFalse($settings->appDebug);
        self::assertSame('127.0.0.1', $settings->database->host);
        self::assertSame(3306, $settings->database->port);
    }

    #[Test]
    public function readsEnvironmentVariables(): void
    {
        $_ENV['APP_ENV'] = 'local';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['DB_HOST'] = 'db.example';
        $_ENV['DB_PORT'] = '3307';
        $_ENV['DB_NAME'] = 'gomrok_test';

        $settings = Settings::fromEnvironment('/nonexistent');

        self::assertSame('local', $settings->appEnv);
        self::assertTrue($settings->appDebug);
        self::assertSame('db.example', $settings->database->host);
        self::assertSame(3307, $settings->database->port);
        self::assertSame('gomrok_test', $settings->database->name);
        self::assertSame(
            'mysql:host=db.example;port=3307;dbname=gomrok_test;charset=utf8mb4',
            $settings->database->dsn(),
        );
    }
}
