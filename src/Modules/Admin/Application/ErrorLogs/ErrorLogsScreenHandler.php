<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ErrorLogs;

use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Application\ErrorLog\ErrorLogDirectory;
use Gomrok\Shared\Application\ErrorLog\ErrorLogFilter;
use Gomrok\Shared\Application\ErrorLog\ErrorLogRecord;

/**
 * Builds the Error Logs screen (Phase 27 — CLAUDE.md: "Viewing error logs" +
 * "Basic reconciliation and debugging"; `.claude/docs/database-design.md`:
 * `resolved_at`/`resolved_by` "support its 'mark resolved' action"). Unlike
 * the Audit Logs screen, this one has a real, schema-backed write action.
 */
final readonly class ErrorLogsScreenHandler
{
    private const PER_PAGE = 50;

    public function __construct(
        private ErrorLogDirectory $errorLogs,
        private AdminUserRepository $adminUsers,
        private ClientDirectory $clients,
    ) {
    }

    public function build(ErrorLogsFilterState $filters, int $page): ErrorLogsScreenResult
    {
        $resolved = match ($filters->resolved) {
            'resolved' => true,
            'unresolved' => false,
            default => null,
        };

        $page = max(1, $page);
        $baseFilter = static fn (int $offset): ErrorLogFilter => new ErrorLogFilter(
            level: $filters->level,
            source: $filters->source,
            clientId: $filters->clientId,
            resolved: $resolved,
            limit: self::PER_PAGE,
            offset: $offset,
        );

        $total = $this->errorLogs->countMatching($baseFilter(0));
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $entries = $this->errorLogs->search($baseFilter(($page - 1) * self::PER_PAGE));

        $unresolvedCount = $this->errorLogs->countMatching(new ErrorLogFilter(
            level: $filters->level,
            source: $filters->source,
            clientId: $filters->clientId,
            resolved: false,
        ));

        return new ErrorLogsScreenResult(
            array_map($this->toRow(...), $entries),
            $total,
            $unresolvedCount,
            $page,
            self::PER_PAGE,
            $totalPages,
            $this->errorLogs->distinctSources(),
            $filters,
        );
    }

    private function toRow(ErrorLogRecord $record): ErrorLogRow
    {
        return new ErrorLogRow(
            $record->id,
            $record->level,
            $record->source,
            $record->message,
            $record->exceptionClass,
            $record->code,
            $record->clientId !== null ? $this->clientLabel($record->clientId) : null,
            $record->correlationId,
            $this->pretty($record->context),
            $record->stackTrace,
            $record->createdAt,
            $record->isResolved(),
            $record->resolvedAt,
            $record->resolvedBy !== null ? $this->adminLabel($record->resolvedBy) : null,
        );
    }

    private function clientLabel(int $clientId): string
    {
        $client = $this->clients->findById($clientId);

        return $client !== null ? $client->name : "Client #{$clientId}";
    }

    private function adminLabel(int $adminUserId): string
    {
        $admin = $this->adminUsers->findById($adminUserId);

        return $admin !== null ? $admin->name() : "Admin user #{$adminUserId}";
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
}
