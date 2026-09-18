<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Reconciliation;

final readonly class ReconciliationScreenResult
{
    /**
     * @param list<ReconciliationRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public int $openCount,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public ReconciliationFilterState $filters,
    ) {
    }
}
