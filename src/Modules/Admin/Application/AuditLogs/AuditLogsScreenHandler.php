<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AuditLogs;

use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Application\Audit\AuditLogDirectory;
use Gomrok\Shared\Application\Audit\AuditLogEntry;
use Gomrok\Shared\Application\Audit\AuditLogFilter;

/**
 * Builds the Audit Logs screen (Phase 27 — CLAUDE.md: "Viewing audit logs").
 * Read-only by nature, unlike every other Phase 27 screen: an audit trail
 * that could be edited or deleted through the same panel it audits would
 * defeat its own purpose as a tamper-evident record, and CLAUDE.md's admin
 * panel list only ever says "viewing," never "managing," audit logs. "Full
 * functionality" here means real filtering and pagination over the real
 * table, not a fabricated write action.
 */
final readonly class AuditLogsScreenHandler
{
    private const PER_PAGE = 50;

    public function __construct(
        private AuditLogDirectory $auditLogs,
        private AdminUserRepository $adminUsers,
        private ClientDirectory $clients,
    ) {
    }

    public function build(AuditLogsFilterState $filters, int $page): AuditLogsScreenResult
    {
        $page = max(1, $page);
        $filter = new AuditLogFilter(
            actorType: $filters->actorType,
            clientId: $filters->clientId,
            action: $filters->action,
            targetType: $filters->targetType,
            targetId: $filters->targetId,
            limit: self::PER_PAGE,
            offset: (max(1, $page) - 1) * self::PER_PAGE,
        );

        $total = $this->auditLogs->countMatching($filter);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        if ($filter->offset !== ($page - 1) * self::PER_PAGE) {
            $filter = new AuditLogFilter(
                actorType: $filter->actorType,
                clientId: $filter->clientId,
                action: $filter->action,
                targetType: $filter->targetType,
                targetId: $filter->targetId,
                limit: self::PER_PAGE,
                offset: ($page - 1) * self::PER_PAGE,
            );
        }

        $entries = $this->auditLogs->search($filter);

        return new AuditLogsScreenResult(
            array_map($this->toRow(...), $entries),
            $total,
            $page,
            self::PER_PAGE,
            $totalPages,
            $this->auditLogs->distinctActions(),
            $filters,
        );
    }

    private function toRow(AuditLogEntry $entry): AuditLogRow
    {
        return new AuditLogRow(
            $entry->id,
            $this->actorLabel($entry),
            $entry->action,
            $entry->targetType !== null
                ? self::label($entry->targetType) . ($entry->targetId !== null ? " #{$entry->targetId}" : '')
                : null,
            $entry->clientId !== null ? $this->clientLabel($entry->clientId) : null,
            $entry->createdAt,
            $this->pretty($entry->before),
            $this->pretty($entry->after),
            $this->pretty($entry->context),
            $entry->ip,
            $entry->correlationId,
        );
    }

    private function actorLabel(AuditLogEntry $entry): string
    {
        return match ($entry->actorType) {
            'admin_user' => $entry->actorId !== null
                ? ($this->adminUsers->findById($entry->actorId)?->name() ?? "Admin user #{$entry->actorId}")
                : 'Unknown admin',
            'client' => $entry->actorId !== null ? $this->clientLabel($entry->actorId) : 'Unknown client',
            default => 'System',
        };
    }

    private function clientLabel(int $clientId): string
    {
        $client = $this->clients->findById($clientId);

        return $client !== null ? $client->name : "Client #{$clientId}";
    }

    /**
     * @param array<array-key, mixed>|null $data
     */
    private function pretty(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : null;
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
