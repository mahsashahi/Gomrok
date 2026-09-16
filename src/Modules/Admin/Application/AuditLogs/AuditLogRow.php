<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AuditLogs;

final readonly class AuditLogRow
{
    public function __construct(
        public int $id,
        public string $actorLabel,
        public string $action,
        public ?string $targetLabel,
        public ?string $clientLabel,
        public string $createdLabel,
        public ?string $beforeJson,
        public ?string $afterJson,
        public ?string $contextJson,
        public ?string $ip,
        public ?string $correlationId,
    ) {
    }

    public function hasDetail(): bool
    {
        return $this->beforeJson !== null || $this->afterJson !== null || $this->contextJson !== null;
    }
}
