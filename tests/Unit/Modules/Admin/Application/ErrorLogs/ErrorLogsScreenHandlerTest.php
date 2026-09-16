<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\ErrorLogs;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\ErrorLogs\ErrorLogsFilterState;
use Gomrok\Modules\Admin\Application\ErrorLogs\ErrorLogsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Shared\Application\ErrorLog\ErrorLogRecord;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryErrorLogDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorLogsScreenHandlerTest extends TestCase
{
    private InMemoryErrorLogDirectory $errorLogs;
    private InMemoryAdminUserRepository $adminUsers;
    private InMemoryClientDirectory $clients;
    private ErrorLogsScreenHandler $handler;

    protected function setUp(): void
    {
        $this->errorLogs = new InMemoryErrorLogDirectory();
        $this->adminUsers = new InMemoryAdminUserRepository();
        $this->clients = new InMemoryClientDirectory();
        $this->handler = new ErrorLogsScreenHandler($this->errorLogs, $this->adminUsers, $this->clients);
    }

    private function emptyFilters(string $resolved = 'unresolved'): ErrorLogsFilterState
    {
        return new ErrorLogsFilterState(null, null, null, $resolved);
    }

    private function record(
        int $id,
        string $level = 'error',
        ?string $resolvedAt = null,
        ?int $resolvedBy = null,
        ?int $clientId = null,
    ): ErrorLogRecord {
        return new ErrorLogRecord(
            $id,
            $level,
            'payments',
            'Something failed',
            'RuntimeException',
            null,
            $clientId,
            'corr-' . $id,
            ['foo' => 'bar'],
            "#0 file.php\n#1 other.php",
            '2026-09-16 12:00:00',
            $resolvedAt,
            $resolvedBy,
        );
    }

    #[Test]
    public function defaultsToShowingOnlyUnresolvedRows(): void
    {
        $this->errorLogs->add($this->record(1));
        $this->errorLogs->add($this->record(2, resolvedAt: '2026-09-16 13:00:00', resolvedBy: null));

        $result = $this->handler->build($this->emptyFilters(), 1);

        self::assertCount(1, $result->rows);
        self::assertSame(1, $result->rows[0]->id);
        self::assertFalse($result->rows[0]->isResolved);
    }

    #[Test]
    public function theAllFilterIncludesResolvedRowsToo(): void
    {
        $this->errorLogs->add($this->record(1));
        $this->errorLogs->add($this->record(2, resolvedAt: '2026-09-16 13:00:00'));

        $result = $this->handler->build($this->emptyFilters('all'), 1);

        self::assertCount(2, $result->rows);
    }

    #[Test]
    public function theUnresolvedCountIgnoresTheResolvedFilterItself(): void
    {
        $this->errorLogs->add($this->record(1));
        $this->errorLogs->add($this->record(2, resolvedAt: '2026-09-16 13:00:00'));

        $result = $this->handler->build($this->emptyFilters('resolved'), 1);

        self::assertSame(1, $result->unresolvedCount);
    }

    #[Test]
    public function resolvesTheResolvingAdminToAName(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $admin = AdminUser::create('Jane Doe', 'jane@example.com', 'hash', AdminRole::Admin, $now);
        $this->adminUsers->save($admin);
        $adminId = $admin->id() ?? 0;

        $this->errorLogs->add($this->record(1, resolvedAt: '2026-09-16 13:00:00', resolvedBy: $adminId));

        $row = $this->handler->build($this->emptyFilters('resolved'), 1)->rows[0];

        self::assertSame('Jane Doe', $row->resolvedByLabel);
    }

    #[Test]
    public function anUnknownResolvingAdminFallsBackToAPlaceholderLabel(): void
    {
        $this->errorLogs->add($this->record(1, resolvedAt: '2026-09-16 13:00:00', resolvedBy: 999));

        $row = $this->handler->build($this->emptyFilters('resolved'), 1)->rows[0];

        self::assertSame('Admin user #999', $row->resolvedByLabel);
    }

    #[Test]
    public function resolvesAKnownClientToItsName(): void
    {
        $this->clients->add(new ClientSnapshot(7, 'acme', 'Acme Inc', ClientStatus::Active, 'USD', null, 'UTC'));
        $this->errorLogs->add($this->record(1, clientId: 7));

        $row = $this->handler->build($this->emptyFilters(), 1)->rows[0];

        self::assertSame('Acme Inc', $row->clientLabel);
    }

    #[Test]
    public function aRowWithNoClientHasNoClientLabel(): void
    {
        $this->errorLogs->add($this->record(1));

        $row = $this->handler->build($this->emptyFilters(), 1)->rows[0];

        self::assertNull($row->clientLabel);
    }

    #[Test]
    public function prettyPrintsTheContextAndCarriesTheStackTrace(): void
    {
        $this->errorLogs->add($this->record(1));

        $row = $this->handler->build($this->emptyFilters(), 1)->rows[0];

        self::assertTrue($row->hasDetail());
        self::assertStringContainsString('"foo": "bar"', $row->contextJson ?? '');
        self::assertStringContainsString('file.php', $row->stackTrace ?? '');
    }

    #[Test]
    public function exposesTheDistinctSourceListForTheFilterDropdown(): void
    {
        $this->errorLogs->add($this->record(1));
        $second = new ErrorLogRecord(2, 'error', 'webhooks', 'x', null, null, null, null, null, null, '2026-09-16 12:00:00', null, null);
        $this->errorLogs->add($second);

        $result = $this->handler->build($this->emptyFilters(), 1);

        self::assertSame(['payments', 'webhooks'], $result->availableSources);
    }

    #[Test]
    public function paginatesAndClampsAnOutOfRangePage(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->errorLogs->add($this->record($i));
        }

        $result = $this->handler->build($this->emptyFilters(), 999);

        self::assertSame(3, $result->totalCount);
        self::assertSame(1, $result->totalPages);
        self::assertSame(1, $result->page);
        self::assertCount(3, $result->rows);
    }
}
