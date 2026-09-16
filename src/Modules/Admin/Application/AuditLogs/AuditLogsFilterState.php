<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AuditLogs;

/**
 * The filter values currently applied, echoed back so the screen's filter
 * form stays populated after a search.
 */
final readonly class AuditLogsFilterState
{
    public function __construct(
        public ?string $actorType,
        public ?int $clientId,
        public ?string $action,
        public ?string $targetType,
        public ?int $targetId,
    ) {
    }
}
