<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use DateTimeImmutable;
use Gomrok\Config\Settings;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverride;
use Gomrok\Modules\Notifications\Infrastructure\PdoClientNotificationRepository;
use Gomrok\Modules\Notifications\Infrastructure\PdoProviderAccountNotificationOverrideRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 28 persistence: `client_notification_logs` and
 * `provider_account_notification_overrides` round-trip through MySQL. Each
 * test rolls back. Skips without MySQL / the seeded schema.
 */
final class ClientNotificationsPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $clientId;
    private int $providerAccountId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM client_notification_logs LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('cnl-test-client', 'CNL Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();

        $typeStatement = $this->pdo->prepare('SELECT id FROM provider_types WHERE code = :code');
        $typeStatement->execute(['code' => 'stripe']);
        $providerTypeId = (int) $typeStatement->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO provider_accounts
                (client_id, provider_type_id, slug, name, mode, status, secret_ciphertext, secret_last_four, created_at)
             VALUES (:c, :pt, 'cnl-stripe', 'CNL Stripe', 'test', 'active', 'x', '0000', :now)",
        )->execute(['c' => $this->clientId, 'pt' => $providerTypeId, 'now' => $now]);
        $this->providerAccountId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function clientNotificationRoundTripsAndDeliverableFiltersByNextAttempt(): void
    {
        $repo = new PdoClientNotificationRepository($this->pdo);
        $now = new DateTimeImmutable();

        $due = ClientNotification::enqueue($this->clientId, NotificationTargetType::Payment, 1, 'payment_status', 'paid', $this->providerAccountId, 'https://acme.example/a', '{"status":"paid"}', $now);
        $repo->save($due);
        $dueId = $due->id();
        self::assertNotNull($dueId);

        $notYetDue = ClientNotification::enqueue($this->clientId, NotificationTargetType::Payment, 2, 'payment_status', 'paid', null, 'https://acme.example/b', '{}', $now);
        $notYetDue->recordRetry($now, 500, null, 'HTTP 500', $now->modify('+1 hour'));
        $repo->save($notYetDue);

        // Reload round-trip.
        $reloaded = $repo->findById($dueId);
        self::assertNotNull($reloaded);
        self::assertSame($this->clientId, $reloaded->clientId());
        self::assertSame(NotificationTargetType::Payment, $reloaded->targetType());
        self::assertSame(1, $reloaded->targetId());
        self::assertSame('paid', $reloaded->statusValue());
        self::assertSame($this->providerAccountId, $reloaded->providerAccountId());
        self::assertSame(ClientNotificationStatus::Pending, $reloaded->status());
        self::assertSame('{"status":"paid"}', $reloaded->payload());

        // Only the due row comes back.
        $deliverable = $repo->findDeliverable(10);
        $deliverableIds = array_map(static fn (ClientNotification $n): ?int => $n->id(), $deliverable);
        self::assertContains($dueId, $deliverableIds);
        self::assertNotContains($notYetDue->id(), $deliverableIds);

        // Update round-trips too.
        $reloaded->recordSuccess($now, 200, 'ok');
        $repo->save($reloaded);
        $afterUpdate = $repo->findById($dueId);
        self::assertNotNull($afterUpdate);
        self::assertSame(ClientNotificationStatus::Sent, $afterUpdate->status());
        self::assertSame(200, $afterUpdate->lastResponseStatus());
    }

    #[Test]
    public function providerAccountOverrideRoundTripsAndIsUniquePerAccountAndPurpose(): void
    {
        $repo = new PdoProviderAccountNotificationOverrideRepository($this->pdo);

        $override = ProviderAccountNotificationOverride::register($this->providerAccountId, 'payment_status', 'https://acme.example/stripe-only');
        $repo->save($override);

        self::assertSame('https://acme.example/stripe-only', $repo->findActiveUrl($this->providerAccountId, 'payment_status'));

        // Re-saving with the same (account, purpose) upserts rather than duplicating.
        $again = ProviderAccountNotificationOverride::register($this->providerAccountId, 'payment_status', 'https://acme.example/updated');
        $repo->save($again);
        self::assertSame('https://acme.example/updated', $repo->findActiveUrl($this->providerAccountId, 'payment_status'));

        $all = $repo->forAccount($this->providerAccountId);
        self::assertCount(1, $all);

        $repo->remove($this->providerAccountId, 'payment_status');
        self::assertNull($repo->findActiveUrl($this->providerAccountId, 'payment_status'));
    }
}
