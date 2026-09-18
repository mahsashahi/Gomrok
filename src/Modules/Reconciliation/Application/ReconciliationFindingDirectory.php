<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Application;

use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;

/**
 * Published read API over `reconciliation_findings` — the admin
 * reconciliation report screen's only reader.
 */
interface ReconciliationFindingDirectory
{
    public function find(int $id): ?ReconciliationFinding;

    /**
     * Newest first.
     *
     * @return list<ReconciliationFinding>
     */
    public function search(ReconciliationFindingFilter $filter): array;

    public function countMatching(ReconciliationFindingFilter $filter): int;

    public function countOpen(?int $clientId = null): int;
}
