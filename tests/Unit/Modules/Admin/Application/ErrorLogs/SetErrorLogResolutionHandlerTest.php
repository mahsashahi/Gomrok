<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\ErrorLogs;

use Gomrok\Modules\Admin\Application\ErrorLogs\SetErrorLogResolution\SetErrorLogResolutionCommand;
use Gomrok\Modules\Admin\Application\ErrorLogs\SetErrorLogResolution\SetErrorLogResolutionHandler;
use Gomrok\Shared\Application\ErrorLog\ErrorLogRecord;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryErrorLogDirectory;
use Gomrok\Tests\Support\InMemoryErrorLogResolver;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SetErrorLogResolutionHandlerTest extends TestCase
{
    private InMemoryErrorLogDirectory $errorLogs;
    private RecordingAuditLogWriter $audit;
    private SetErrorLogResolutionHandler $handler;

    protected function setUp(): void
    {
        $this->errorLogs = new InMemoryErrorLogDirectory();
        $this->audit = new RecordingAuditLogWriter();

        $this->handler = new SetErrorLogResolutionHandler(
            $this->errorLogs,
            new InMemoryErrorLogResolver($this->errorLogs),
            $this->audit,
            new SynchronousTransactions(),
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );
    }

    private function unresolvedRecord(int $id = 1): ErrorLogRecord
    {
        return new ErrorLogRecord($id, 'error', 'payments', 'Something failed', null, null, null, null, null, null, '2026-09-16 11:00:00', null, null);
    }

    #[Test]
    public function marksAnUnresolvedRowResolved(): void
    {
        $this->errorLogs->add($this->unresolvedRecord());

        $result = $this->handler->handle(new SetErrorLogResolutionCommand(1, true, actorId: 5));

        self::assertTrue($result->isOk());
        $record = $this->errorLogs->find(1);
        self::assertNotNull($record);
        self::assertTrue($record->isResolved());
        self::assertSame('2026-09-16 12:00:00', $record->resolvedAt);
        self::assertSame(5, $record->resolvedBy);
        self::assertSame(['error_log.resolved'], $this->audit->actions());
    }

    #[Test]
    public function reopensAResolvedRow(): void
    {
        $this->errorLogs->add(new ErrorLogRecord(1, 'error', 'payments', 'x', null, null, null, null, null, null, '2026-09-16 11:00:00', '2026-09-16 11:30:00', 5));

        $result = $this->handler->handle(new SetErrorLogResolutionCommand(1, false, actorId: 9));

        self::assertTrue($result->isOk());
        $record = $this->errorLogs->find(1);
        self::assertNotNull($record);
        self::assertFalse($record->isResolved());
        self::assertSame(['error_log.reopened'], $this->audit->actions());
    }

    #[Test]
    public function isIdempotentWhenAlreadyInTheTargetState(): void
    {
        $this->errorLogs->add($this->unresolvedRecord());

        $result = $this->handler->handle(new SetErrorLogResolutionCommand(1, false, actorId: 5));

        self::assertTrue($result->isOk());
        self::assertSame([], $this->audit->actions());
    }

    #[Test]
    public function idempotentReResolveDoesNotDoubleWriteAudit(): void
    {
        $this->errorLogs->add($this->unresolvedRecord());
        $this->handler->handle(new SetErrorLogResolutionCommand(1, true, actorId: 5));

        $result = $this->handler->handle(new SetErrorLogResolutionCommand(1, true, actorId: 5));

        self::assertTrue($result->isOk());
        self::assertSame(['error_log.resolved'], $this->audit->actions());
    }

    #[Test]
    public function rejectsAnUnknownErrorLog(): void
    {
        $result = $this->handler->handle(new SetErrorLogResolutionCommand(999, true, actorId: 5));

        self::assertTrue($result->isErr());
        self::assertSame('error_log.not_found', $result->error()->code);
    }
}
