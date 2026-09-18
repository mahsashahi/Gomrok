<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Reconciliation\Application;

use DateTimeImmutable;
use Gomrok\Modules\Reconciliation\Application\ResolveReconciliationFinding\ResolveReconciliationFindingCommand;
use Gomrok\Modules\Reconciliation\Application\ResolveReconciliationFinding\ResolveReconciliationFindingHandler;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryReconciliationFindingRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResolveReconciliationFindingHandlerTest extends TestCase
{
    private InMemoryReconciliationFindingRepository $findings;
    private RecordingAuditLogWriter $audit;
    private ResolveReconciliationFindingHandler $handler;

    protected function setUp(): void
    {
        $this->findings = new InMemoryReconciliationFindingRepository();
        $this->audit = new RecordingAuditLogWriter();

        $this->handler = new ResolveReconciliationFindingHandler(
            $this->findings,
            $this->audit,
            new SynchronousTransactions(),
            new FrozenClock('2026-09-17T12:00:00+00:00'),
        );
    }

    private function seed(int $clientId = 1): ReconciliationFinding
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

        return $finding;
    }

    #[Test]
    public function marksAnOpenFindingResolvedAndWritesAnAuditEntry(): void
    {
        $finding = $this->seed();
        $id = $finding->id();
        \assert($id !== null);

        $result = $this->handler->handle(new ResolveReconciliationFindingCommand($id, actorId: 5));

        self::assertTrue($result->isOk());
        $stored = $this->findings->findById($id);
        self::assertNotNull($stored);
        self::assertTrue($stored->isResolved());
        self::assertSame(5, $stored->resolvedBy());
        self::assertSame(['reconciliation_finding.resolved'], $this->audit->actions());
    }

    #[Test]
    public function isIdempotentOnAnAlreadyResolvedFinding(): void
    {
        $finding = $this->seed();
        $id = $finding->id();
        \assert($id !== null);
        $finding->markResolved(1, new DateTimeImmutable('2026-09-17T11:30:00+00:00'));

        $result = $this->handler->handle(new ResolveReconciliationFindingCommand($id, actorId: 5));

        self::assertTrue($result->isOk());
        self::assertSame([], $this->audit->actions());
        self::assertSame(1, $this->findings->findById($id)?->resolvedBy());
    }

    #[Test]
    public function rejectsAnUnknownFinding(): void
    {
        $result = $this->handler->handle(new ResolveReconciliationFindingCommand(999, actorId: 5));

        self::assertTrue($result->isErr());
        self::assertSame('reconciliation_finding.not_found', $result->error()->code);
    }
}
