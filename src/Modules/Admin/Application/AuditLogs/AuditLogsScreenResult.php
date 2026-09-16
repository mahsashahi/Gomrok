<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AuditLogs;

final readonly class AuditLogsScreenResult
{
    /**
     * @param list<AuditLogRow> $rows
     * @param list<string>      $availableActions
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public array $availableActions,
        public AuditLogsFilterState $filters,
    ) {
    }
}
