<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Reconciliation;

use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingDirectory;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingFilter;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;

/**
 * Builds the admin Reconciliation report screen (Phase 29 — CLAUDE.md:
 * "Basic reconciliation and debugging"). Mirrors
 * {@see \Gomrok\Modules\Admin\Application\ErrorLogs\ErrorLogsScreenHandler}'s
 * shape: a detection-only report with a "mark resolved" (never "fixed")
 * write action, defaulting to showing only what still needs attention.
 */
final readonly class ReconciliationScreenHandler
{
    private const PER_PAGE = 50;

    public function __construct(
        private ReconciliationFindingDirectory $findings,
        private AdminUserRepository $adminUsers,
        private ClientDirectory $clients,
    ) {
    }

    public function build(ReconciliationFilterState $filters, int $page): ReconciliationScreenResult
    {
        $page = max(1, $page);
        $baseFilter = static fn (int $offset): ReconciliationFindingFilter => new ReconciliationFindingFilter(
            clientId: $filters->clientId,
            resolution: $filters->resolution,
            limit: self::PER_PAGE,
            offset: $offset,
        );

        $total = $this->findings->countMatching($baseFilter(0));
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $entries = $this->findings->search($baseFilter(($page - 1) * self::PER_PAGE));
        $openCount = $this->findings->countOpen($filters->clientId);

        return new ReconciliationScreenResult(
            array_map($this->toRow(...), $entries),
            $total,
            $openCount,
            $page,
            self::PER_PAGE,
            $totalPages,
            $filters,
        );
    }

    private function toRow(ReconciliationFinding $finding): ReconciliationRow
    {
        $id = $finding->id();
        \assert($id !== null);

        return new ReconciliationRow(
            $id,
            $this->clientLabel($finding->clientId()),
            $finding->targetType()->value,
            $finding->targetId(),
            $finding->localStatus(),
            $finding->providerStatusRaw(),
            $finding->mappedProviderStatus(),
            $finding->detectedAt()->format('Y-m-d H:i'),
            $finding->isResolved(),
            $finding->resolvedAt()?->format('Y-m-d H:i'),
            $finding->resolvedBy() !== null ? $this->adminLabel($finding->resolvedBy()) : null,
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
}
