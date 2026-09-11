<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Checkout\Infrastructure\PdoCheckoutAttemptRepository;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingDecisionSnapshotRepository;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 18 round-trip against real MySQL: a `checkout_attempts` row survives a
 * status transition, and a `pricing_decision_snapshots` row's JSON payload
 * round-trips faithfully. Skipped locally (no MySQL); runs in CI.
 */
final class CheckoutAttemptPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $clientId;
    private int $packageId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM checkout_attempts LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('ca-test-client', 'CA Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO packages (client_id, code, name, status, created_at) VALUES (:c, 'pro', 'Pro', 'active', :now)",
        )->execute(['c' => $this->clientId, 'now' => $now]);
        $this->packageId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function aCheckoutAttemptAndItsPricingSnapshotRoundTrip(): void
    {
        $attempts = new PdoCheckoutAttemptRepository($this->pdo);
        $snapshots = new PdoPricingDecisionSnapshotRepository($this->pdo);
        $now = new \DateTimeImmutable('2026-09-11T12:00:00+00:00');

        $attempt = CheckoutAttempt::start($this->clientId, 'user-1', 'order-1', $this->packageId, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $now);
        $attempts->save($attempt);
        $attemptId = $attempt->id();
        self::assertNotNull($attemptId);

        $reloaded = $attempts->findByAttemptReference($this->clientId, 'order-1');
        self::assertNotNull($reloaded);
        self::assertSame(CheckoutAttemptStatus::Started, $reloaded->status());

        $error = $reloaded->transitionTo(CheckoutAttemptStatus::PricingResolved, $now);
        self::assertNull($error);
        $attempts->save($reloaded);

        $reReloaded = $attempts->findById($attemptId);
        self::assertNotNull($reReloaded);
        self::assertSame(CheckoutAttemptStatus::PricingResolved, $reReloaded->status());

        $price = new ResolvedPrice($this->packageId, 'pro', 2900, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $snapshotId = $snapshots->save(PricingDecisionSnapshot::of($attemptId, $this->clientId, $price, $now));
        self::assertGreaterThan(0, $snapshotId);

        $loadedSnapshot = $snapshots->findByCheckoutAttemptId($attemptId);
        self::assertNotNull($loadedSnapshot);
        self::assertSame(2900, $loadedSnapshot->amountMinor);
        self::assertSame('EUR', $loadedSnapshot->currencyCode);
        self::assertSame('baseline', $loadedSnapshot->source);
        self::assertSame('pro', $loadedSnapshot->payload['package_code'] ?? null);
    }
}
