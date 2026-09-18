<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use DateTimeImmutable;
use Gomrok\Config\Settings;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingFilter;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use Gomrok\Modules\Reconciliation\Infrastructure\PdoReconciliationFindingDirectory;
use Gomrok\Modules\Reconciliation\Infrastructure\PdoReconciliationFindingRepository;
use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Modules\Webhooks\Infrastructure\PdoWebhookEventRepository;
use Gomrok\Shared\Application\Jobs\JobFilter;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobStatus;
use Gomrok\Shared\Infrastructure\Persistence\PdoJobDirectory;
use Gomrok\Shared\Infrastructure\Persistence\PdoJobRepository;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 29 persistence: `jobs`, `reconciliation_findings`, and
 * `webhook_events.subscription_reference` round-trip through MySQL. Each
 * test rolls back. Skips without MySQL / the seeded schema.
 */
final class JobsReconciliationPersistenceTest extends TestCase
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
            $this->pdo->query('SELECT 1 FROM jobs LIMIT 1');
            $this->pdo->query('SELECT 1 FROM reconciliation_findings LIMIT 1');
            $this->pdo->query('SELECT subscription_reference FROM webhook_events LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('jrp-test-client', 'JRP Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();

        $typeStatement = $this->pdo->prepare('SELECT id FROM provider_types WHERE code = :code');
        $typeStatement->execute(['code' => 'stripe']);
        $providerTypeId = (int) $typeStatement->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO provider_accounts
                (client_id, provider_type_id, slug, name, mode, status, secret_ciphertext, secret_last_four, created_at)
             VALUES (:c, :pt, 'jrp-stripe', 'JRP Stripe', 'test', 'active', 'x', '0000', :now)",
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
    public function jobRoundTripsAndClaimDueOnlyReturnsWhatIsActuallyDue(): void
    {
        $repo = new PdoJobRepository($this->pdo, new TransactionRunner($this->pdo));
        $directory = new PdoJobDirectory($this->pdo);
        $now = new DateTimeImmutable();

        $due = Job::schedule('jrp_test_type', '{"foo":"bar"}', $now, $now);
        $repo->save($due);
        $dueId = $due->id();
        self::assertNotNull($dueId);

        $notYetDue = Job::schedule('jrp_test_type', null, $now->modify('+1 hour'), $now);
        $repo->save($notYetDue);

        $reloaded = $repo->findById($dueId);
        self::assertNotNull($reloaded);
        self::assertSame('jrp_test_type', $reloaded->type());
        self::assertSame('{"foo":"bar"}', $reloaded->payload());
        self::assertSame(JobStatus::Pending, $reloaded->status());

        $claimed = $repo->claimDue(10, 'jrp-worker', $now);
        $claimedIds = array_map(static fn (Job $j): ?int => $j->id(), $claimed);
        self::assertContains($dueId, $claimedIds);
        self::assertNotContains($notYetDue->id(), $claimedIds);

        $reclaimedById = $repo->claimById($dueId, 'jrp-worker-2', $now);
        self::assertNull($reclaimedById, 'already Processing — claimById must not re-claim it');

        $afterClaim = $repo->findById($dueId);
        self::assertNotNull($afterClaim);
        self::assertSame(JobStatus::Processing, $afterClaim->status());
        self::assertSame(1, $afterClaim->attempts());

        $afterClaim->recordSuccess('{"scanned":1}', $now->modify('+5 minutes'), $now);
        $repo->save($afterClaim);

        $searched = $directory->search(new JobFilter(status: 'pending', type: 'jrp_test_type'));
        self::assertNotEmpty($searched);
        self::assertContains($dueId, array_map(static fn (Job $j): ?int => $j->id(), $searched));
        self::assertGreaterThanOrEqual(1, $directory->countMatching(new JobFilter(type: 'jrp_test_type')));
        self::assertContains('jrp_test_type', $directory->distinctTypes());
    }

    #[Test]
    public function ensureScheduledIsANoOpWhenAPendingRowAlreadyExists(): void
    {
        $repo = new PdoJobRepository($this->pdo, new TransactionRunner($this->pdo));
        $now = new DateTimeImmutable();

        $repo->ensureScheduled('jrp_recurring_type', $now, $now);
        $repo->ensureScheduled('jrp_recurring_type', $now, $now);

        $directory = new PdoJobDirectory($this->pdo);
        self::assertSame(1, $directory->countMatching(new JobFilter(type: 'jrp_recurring_type')));
    }

    #[Test]
    public function reconciliationFindingRoundTripsAndResolutionFiltersWork(): void
    {
        $repo = new PdoReconciliationFindingRepository($this->pdo);
        $directory = new PdoReconciliationFindingDirectory($this->pdo);
        $now = new DateTimeImmutable();

        $finding = ReconciliationFinding::detect(
            $this->clientId,
            ReconciliationTargetType::Payment,
            42,
            'pending',
            'paid',
            'paid',
            $now,
        );
        $repo->save($finding);
        $findingId = $finding->id();
        self::assertNotNull($findingId);

        $reloaded = $repo->findById($findingId);
        self::assertNotNull($reloaded);
        self::assertSame($this->clientId, $reloaded->clientId());
        self::assertSame(ReconciliationTargetType::Payment, $reloaded->targetType());
        self::assertSame(42, $reloaded->targetId());
        self::assertFalse($reloaded->isResolved());

        self::assertSame(1, $directory->countOpen($this->clientId));

        $reloaded->markResolved(1, $now);
        $repo->save($reloaded);

        self::assertSame(0, $directory->countOpen($this->clientId));
        $resolved = $directory->search(new ReconciliationFindingFilter(clientId: $this->clientId, resolution: 'resolved'));
        self::assertNotEmpty($resolved);
        self::assertSame($findingId, $resolved[0]->id());
    }

    #[Test]
    public function webhookEventSubscriptionReferenceRoundTrips(): void
    {
        $repo = new PdoWebhookEventRepository($this->pdo);
        $now = new DateTimeImmutable();

        $event = WebhookEvent::receive(
            $this->clientId,
            $this->providerAccountId,
            'stripe',
            'evt_jrp_1',
            'invoice.payment_succeeded',
            'succeeded',
            'pi_jrp_1',
            '{"type":"invoice.payment_succeeded"}',
            [],
            $now,
            'sub_jrp_1',
        );
        $repo->save($event);
        $eventId = $event->id();
        self::assertNotNull($eventId);

        $reloaded = $repo->findById($eventId);
        self::assertNotNull($reloaded);
        self::assertSame('sub_jrp_1', $reloaded->subscriptionReference());
    }
}
