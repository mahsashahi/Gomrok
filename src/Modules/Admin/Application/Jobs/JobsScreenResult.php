<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs;

final readonly class JobsScreenResult
{
    /**
     * @param list<JobRow>    $rows
     * @param list<string>    $availableTypes
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public int $failedCount,
        public int $alertingCount,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public array $availableTypes,
        public JobsFilterState $filters,
    ) {
    }
}
