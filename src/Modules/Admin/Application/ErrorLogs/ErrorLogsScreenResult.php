<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ErrorLogs;

final readonly class ErrorLogsScreenResult
{
    /**
     * @param list<ErrorLogRow> $rows
     * @param list<string>      $availableSources
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public int $unresolvedCount,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public array $availableSources,
        public ErrorLogsFilterState $filters,
    ) {
    }
}
