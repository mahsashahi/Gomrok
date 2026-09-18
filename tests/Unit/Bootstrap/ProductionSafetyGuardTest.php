<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Bootstrap;

use Gomrok\Bootstrap\ProductionSafetyGuard;
use Gomrok\Bootstrap\ProductionSafetyViolation;
use Gomrok\Config\DatabaseSettings;
use Gomrok\Config\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionSafetyGuardTest extends TestCase
{
    private function database(): DatabaseSettings
    {
        return new DatabaseSettings('127.0.0.1', 3306, 'gomrok', 'gomrok', 'secret', 'utf8mb4');
    }

    private function settings(
        string $appEnv,
        bool $appDebug = false,
        ?string $encryptionKeyBase64 = 'a-real-32-byte-base64-key-value',
        string $checkoutReturnTokenSecret = 'a-real-secret-not-the-default',
    ): Settings {
        return new Settings(
            appEnv: $appEnv,
            appDebug: $appDebug,
            database: $this->database(),
            encryptionKeyBase64: $encryptionKeyBase64,
            checkoutReturnTokenSecret: $checkoutReturnTokenSecret,
        );
    }

    #[Test]
    public function localIsAlwaysSafeRegardlessOfOtherSettings(): void
    {
        $this->expectNotToPerformAssertions();

        ProductionSafetyGuard::check($this->settings('local', appDebug: true, encryptionKeyBase64: null, checkoutReturnTokenSecret: 'gomrokimo'));
    }

    #[Test]
    public function testingIsAlwaysSafeRegardlessOfOtherSettings(): void
    {
        $this->expectNotToPerformAssertions();

        ProductionSafetyGuard::check($this->settings('testing', appDebug: true, encryptionKeyBase64: null, checkoutReturnTokenSecret: 'gomrokimo'));
    }

    #[Test]
    public function aFullySafeProductionConfigPasses(): void
    {
        $this->expectNotToPerformAssertions();

        ProductionSafetyGuard::check($this->settings('production'));
    }

    #[Test]
    public function rejectsDebugModeOutsideLocalTesting(): void
    {
        $this->expectException(ProductionSafetyViolation::class);

        ProductionSafetyGuard::check($this->settings('production', appDebug: true));
    }

    #[Test]
    public function rejectsTheHardcodedDefaultCheckoutReturnTokenSecret(): void
    {
        $this->expectException(ProductionSafetyViolation::class);

        ProductionSafetyGuard::check($this->settings('production', checkoutReturnTokenSecret: 'gomrokimo'));
    }

    #[Test]
    public function rejectsAMissingEncryptionKey(): void
    {
        $this->expectException(ProductionSafetyViolation::class);

        ProductionSafetyGuard::check($this->settings('production', encryptionKeyBase64: null));
    }

    #[Test]
    public function reportsEveryViolationAtOnce(): void
    {
        try {
            ProductionSafetyGuard::check($this->settings('production', appDebug: true, encryptionKeyBase64: null, checkoutReturnTokenSecret: 'gomrokimo'));
            self::fail('expected a ProductionSafetyViolation');
        } catch (ProductionSafetyViolation $e) {
            self::assertCount(3, $e->violations);
        }
    }

    #[Test]
    public function anUnrecognisedEnvironmentIsTreatedAsUnsafe(): void
    {
        $this->expectException(ProductionSafetyViolation::class);

        // A typo'd APP_ENV (e.g. "prod" or "staging") must not silently
        // fall through as "safe" the way "local"/"testing" do.
        ProductionSafetyGuard::check($this->settings('staging', appDebug: true));
    }
}
