<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Application\ResolveReconciliationFinding;

use Gomrok\Modules\Reconciliation\Domain\ReconciliationFindingRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * `reconciliation.resolve` marks a finding reviewed — never "fixed", since
 * reconciliation itself never mutates the payment/subscription it reports
 * drift on. Idempotent: resolving an already-resolved finding is a no-op.
 */
final readonly class ResolveReconciliationFindingHandler
{
    public function __construct(
        private ReconciliationFindingRepository $findings,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ResolveReconciliationFindingCommand $command): Result
    {
        $finding = $this->findings->findById($command->findingId);
        if ($finding === null) {
            return Result::err(DomainError::notFound('reconciliation_finding.not_found', "Reconciliation finding {$command->findingId} was not found."));
        }

        if ($finding->isResolved()) {
            return Result::ok(null);
        }

        $this->transactions->run(function () use ($finding, $command): void {
            $finding->markResolved($command->actorId, $this->clock->now());
            $this->findings->save($finding);

            $this->audit->record(
                AuditEntry::forAdminUser($command->actorId, $finding->clientId(), 'reconciliation_finding.resolved')
                    ->withTarget('reconciliation_finding', $command->findingId),
            );
        });

        return Result::ok(null);
    }
}
