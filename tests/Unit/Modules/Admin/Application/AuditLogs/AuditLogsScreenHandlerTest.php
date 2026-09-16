<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\AuditLogs;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\AuditLogs\AuditLogsFilterState;
use Gomrok\Modules\Admin\Application\AuditLogs\AuditLogsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Shared\Application\Audit\AuditLogEntry;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\InMemoryAuditLogDirectory;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditLogsScreenHandlerTest extends TestCase
{
    private InMemoryAuditLogDirectory $auditLogs;
    private InMemoryAdminUserRepository $adminUsers;
    private InMemoryClientDirectory $clients;
    private AuditLogsScreenHandler $handler;

    protected function setUp(): void
    {
        $this->auditLogs = new InMemoryAuditLogDirectory();
        $this->adminUsers = new InMemoryAdminUserRepository();
        $this->clients = new InMemoryClientDirectory();
        $this->handler = new AuditLogsScreenHandler($this->auditLogs, $this->adminUsers, $this->clients);
    }

    private function emptyFilters(): AuditLogsFilterState
    {
        return new AuditLogsFilterState(null, null, null, null, null);
    }

    #[Test]
    public function resolvesAnAdminUserActorToTheirName(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $admin = AdminUser::create('Jane Doe', 'jane@example.com', 'hash', AdminRole::Admin, $now);
        $this->adminUsers->save($admin);
        $adminId = $admin->id() ?? 0;

        $this->auditLogs->add(new AuditLogEntry(1, 'admin_user', $adminId, null, 'voucher.created', 'voucher', 42, null, ['code' => 'X'], null, null, null, null, '2026-09-16 12:00:00'));

        $result = $this->handler->build($this->emptyFilters(), 1);

        self::assertCount(1, $result->rows);
        self::assertSame('Jane Doe', $result->rows[0]->actorLabel);
        self::assertSame('Voucher #42', $result->rows[0]->targetLabel);
    }

    #[Test]
    public function resolvesAClientActorAndClientScopeToTheClientName(): void
    {
        $this->clients->add(new ClientSnapshot(7, 'acme', 'Acme Inc', ClientStatus::Active, 'USD', null, 'UTC'));

        $this->auditLogs->add(new AuditLogEntry(1, 'client', 7, 7, 'payment.created', 'payment', 5, null, null, null, null, null, null, '2026-09-16 12:00:00'));

        $result = $this->handler->build($this->emptyFilters(), 1);

        self::assertSame('Acme Inc', $result->rows[0]->actorLabel);
        self::assertSame('Acme Inc', $result->rows[0]->clientLabel);
    }

    #[Test]
    public function systemActorLabelsAsSystemAndCarriesNoClient(): void
    {
        $this->auditLogs->add(new AuditLogEntry(1, 'system', null, null, 'webhook.processed', null, null, null, null, null, null, null, null, '2026-09-16 12:00:00'));

        $result = $this->handler->build($this->emptyFilters(), 1);

        self::assertSame('System', $result->rows[0]->actorLabel);
        self::assertNull($result->rows[0]->targetLabel);
        self::assertNull($result->rows[0]->clientLabel);
    }

    #[Test]
    public function prettyPrintsBeforeAfterAndContext(): void
    {
        $this->auditLogs->add(new AuditLogEntry(
            1,
            'system',
            null,
            null,
            'voucher.updated',
            'voucher',
            1,
            ['status' => 'active'],
            ['status' => 'disabled'],
            ['reason' => 'fraud'],
            null,
            null,
            null,
            '2026-09-16 12:00:00',
        ));

        $row = $this->handler->build($this->emptyFilters(), 1)->rows[0];

        self::assertTrue($row->hasDetail());
        self::assertStringContainsString('"status": "active"', $row->beforeJson ?? '');
        self::assertStringContainsString('"status": "disabled"', $row->afterJson ?? '');
        self::assertStringContainsString('"reason": "fraud"', $row->contextJson ?? '');
    }

    #[Test]
    public function aRowWithNoBeforeAfterOrContextHasNoDetail(): void
    {
        $this->auditLogs->add(new AuditLogEntry(1, 'system', null, null, 'ping', null, null, null, null, null, null, null, null, '2026-09-16 12:00:00'));

        $row = $this->handler->build($this->emptyFilters(), 1)->rows[0];

        self::assertFalse($row->hasDetail());
    }

    #[Test]
    public function paginatesAndClampsAnOutOfRangePage(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->auditLogs->add(new AuditLogEntry($i, 'system', null, null, "action.{$i}", null, null, null, null, null, null, null, null, '2026-09-16 12:00:00'));
        }

        $result = $this->handler->build($this->emptyFilters(), 999);

        self::assertSame(5, $result->totalCount);
        self::assertSame(1, $result->totalPages);
        self::assertSame(1, $result->page);
        self::assertCount(5, $result->rows);
    }

    #[Test]
    public function exposesTheDistinctActionListForTheFilterDropdown(): void
    {
        $this->auditLogs->add(new AuditLogEntry(1, 'system', null, null, 'voucher.created', null, null, null, null, null, null, null, null, '2026-09-16 12:00:00'));
        $this->auditLogs->add(new AuditLogEntry(2, 'system', null, null, 'client.created', null, null, null, null, null, null, null, null, '2026-09-16 12:00:00'));

        $result = $this->handler->build($this->emptyFilters(), 1);

        self::assertSame(['client.created', 'voucher.created'], $result->availableActions);
    }

    #[Test]
    public function anUnknownAdminActorFallsBackToAPlaceholderLabel(): void
    {
        $this->auditLogs->add(new AuditLogEntry(1, 'admin_user', 999, null, 'admin_user.created', 'admin_user', 999, null, null, null, null, null, null, '2026-09-16 12:00:00'));

        $row = $this->handler->build($this->emptyFilters(), 1)->rows[0];

        self::assertSame('Admin user #999', $row->actorLabel);
    }
}
