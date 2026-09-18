<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingDirectory;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingFilter;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFindingRepository;

/**
 * A single double over both the write port ({@see ReconciliationFindingRepository})
 * and the read port ({@see ReconciliationFindingDirectory}) — same shape as
 * {@see InMemoryJobRepository}: entities are stored by reference, so a
 * mutation like {@see ReconciliationFinding::markResolved()} is visible to
 * every reader without an explicit "replace" call.
 */
final class InMemoryReconciliationFindingRepository implements ReconciliationFindingRepository, ReconciliationFindingDirectory
{
    /** @var array<int, ReconciliationFinding> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(ReconciliationFinding $finding): void
    {
        if ($finding->id() === null) {
            $finding->assignId($this->nextId++);
        }
        $id = $finding->id();
        \assert($id !== null);
        $this->byId[$id] = $finding;
    }

    public function findById(int $id): ?ReconciliationFinding
    {
        return $this->byId[$id] ?? null;
    }

    public function find(int $id): ?ReconciliationFinding
    {
        return $this->findById($id);
    }

    public function search(ReconciliationFindingFilter $filter): array
    {
        $matching = array_values(array_filter($this->byId, fn (ReconciliationFinding $f): bool => $this->matches($f, $filter)));
        usort($matching, static fn (ReconciliationFinding $a, ReconciliationFinding $b): int => ($b->id() ?? 0) <=> ($a->id() ?? 0));

        return \array_slice($matching, $filter->offset, $filter->limit);
    }

    public function countMatching(ReconciliationFindingFilter $filter): int
    {
        return \count(array_filter($this->byId, fn (ReconciliationFinding $f): bool => $this->matches($f, $filter)));
    }

    public function countOpen(?int $clientId = null): int
    {
        return \count(array_filter(
            $this->byId,
            static fn (ReconciliationFinding $f): bool => !$f->isResolved() && ($clientId === null || $f->clientId() === $clientId),
        ));
    }

    private function matches(ReconciliationFinding $finding, ReconciliationFindingFilter $filter): bool
    {
        if ($filter->clientId !== null && $finding->clientId() !== $filter->clientId) {
            return false;
        }

        return match ($filter->resolution) {
            'open' => !$finding->isResolved(),
            'resolved' => $finding->isResolved(),
            default => true,
        };
    }
}
