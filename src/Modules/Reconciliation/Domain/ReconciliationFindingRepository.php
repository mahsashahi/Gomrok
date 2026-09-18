<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Domain;

/**
 * Persistence port for {@see ReconciliationFinding}.
 */
interface ReconciliationFindingRepository
{
    public function save(ReconciliationFinding $finding): void;

    public function findById(int $id): ?ReconciliationFinding;
}
