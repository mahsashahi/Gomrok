<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Reconciliation;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Reconciliation\ReconciliationFilterState;
use Gomrok\Modules\Admin\Application\Reconciliation\ReconciliationScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryReconciliationFindingRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReconciliationScreenHandlerTest extends TestCase
{
    private InMemoryReconciliationFindingRepository $findings;
    private InMemoryAdminUserRepository $adminUsers;
    private InMemoryClientDirectory $clients;
    private ReconciliationScreenHandler $handler;

    protected function setUp(): void
    {
        $this->findings = new InMemoryReconciliationFindingRepository();
        $this->adminUsers = new InMemoryAdminUserRepository();
        $this->clients = new InMemoryClientDirectory();
        $this->handler = new ReconciliationScreenHandler($this->findings, $this->adminUsers, $this->clients);
    }

    private function seed(int $clientId = 1, bool $resolved = false): ReconciliationFinding
    {
        $finding = ReconciliationFinding::detect(
            $clientId,
            ReconciliationTargetType::Payment,
            42,
            'pending',
            'paid',
            'paid',
            new DateTimeImmutable('2026-09-17T11:00:00+00:00'),
        );
        $this->findings->save($finding);
        if ($resolved) {
            $finding->markResolved(1, new DateTimeImmutable('2026-09-17T11:30:00+00:00'));
        }

        return $finding;
    }

    #[Test]
    public function defaultsToShowingOnlyOpenFindings(): void
    {
        $this->seed();
        $this->seed(resolved: true);

        $result = $this->handler->build(new ReconciliationFilterState(), 1);

        self::assertCount(1, $result->rows);
        self::assertFalse($result->rows[0]->isResolved);
    }

    #[Test]
    public function theAllFilterIncludesResolvedFindingsToo(): void
    {
        $this->seed();
        $this->seed(resolved: true);

        $result = $this->handler->build(new ReconciliationFilterState(resolution: 'all'), 1);

        self::assertCount(2, $result->rows);
    }

    #[Test]
    public function theOpenCountIgnoresTheResolutionFilterItself(): void
    {
        $this->seed();
        $this->seed(resolved: true);

        $result = $this->handler->build(new ReconciliationFilterState(resolution: 'resolved'), 1);

        self::assertSame(1, $result->openCount);
    }

    #[Test]
    public function resolvesAKnownClientToItsName(): void
    {
        $this->clients->add(new ClientSnapshot(7, 'acme', 'Acme Inc', ClientStatus::Active, 'USD', null, 'UTC'));
        $this->seed(clientId: 7);

        $row = $this->handler->build(new ReconciliationFilterState(), 1)->rows[0];

        self::assertSame('Acme Inc', $row->clientLabel);
    }

    #[Test]
    public function anUnknownClientFallsBackToAPlaceholderLabel(): void
    {
        $this->seed(clientId: 999);

        $row = $this->handler->build(new ReconciliationFilterState(), 1)->rows[0];

        self::assertSame('Client #999', $row->clientLabel);
    }

    #[Test]
    public function resolvesTheResolvingAdminToAName(): void
    {
        $admin = AdminUser::create('Jane Doe', 'jane@example.com', 'hash', AdminRole::Admin, new DateTimeImmutable());
        $this->adminUsers->save($admin);
        $adminId = $admin->id() ?? 0;

        $finding = $this->seed();
        $finding->markResolved($adminId, new DateTimeImmutable('2026-09-17T11:30:00+00:00'));

        $row = $this->handler->build(new ReconciliationFilterState(resolution: 'resolved'), 1)->rows[0];

        self::assertSame('Jane Doe', $row->resolvedByLabel);
    }

    #[Test]
    public function anUnknownResolvingAdminFallsBackToAPlaceholderLabel(): void
    {
        $finding = $this->seed();
        $finding->markResolved(999, new DateTimeImmutable('2026-09-17T11:30:00+00:00'));

        $row = $this->handler->build(new ReconciliationFilterState(resolution: 'resolved'), 1)->rows[0];

        self::assertSame('Admin user #999', $row->resolvedByLabel);
    }

    #[Test]
    public function carriesLocalAndProviderStatusOntoTheRow(): void
    {
        $this->seed();

        $row = $this->handler->build(new ReconciliationFilterState(), 1)->rows[0];

        self::assertSame('pending', $row->localStatus);
        self::assertSame('paid', $row->providerStatusRaw);
        self::assertSame('paid', $row->mappedProviderStatus);
        self::assertSame('payment', $row->targetType);
        self::assertSame(42, $row->targetId);
    }

    #[Test]
    public function filtersByClientId(): void
    {
        $this->seed(clientId: 1);
        $this->seed(clientId: 2);

        $result = $this->handler->build(new ReconciliationFilterState(clientId: 2), 1);

        self::assertCount(1, $result->rows);
        self::assertSame(2, $this->findings->findById($result->rows[0]->id)?->clientId());
    }

    #[Test]
    public function paginatesAndClampsAnOutOfRangePage(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->seed();
        }

        $result = $this->handler->build(new ReconciliationFilterState(), 999);

        self::assertSame(3, $result->totalCount);
        self::assertSame(1, $result->totalPages);
        self::assertSame(1, $result->page);
        self::assertCount(3, $result->rows);
    }
}
