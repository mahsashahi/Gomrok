<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\AuditLogs\AuditLogsFilterState;
use Gomrok\Modules\Admin\Application\AuditLogs\AuditLogsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/audit-logs` (Phase 27 — CLAUDE.md: "Viewing audit logs"). A
 * read-only screen by design (see {@see AuditLogsScreenHandler}'s own
 * docblock) — filterable and paginated over the real `audit_logs` table,
 * gated on `audit_logs.view` like every purely-viewing screen in the suggested
 * permission list.
 */
final readonly class AdminAuditLogsAction
{
    public function __construct(
        private AdminContext $context,
        private AuditLogsScreenHandler $screen,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::AuditLogsView)) {
            return AdminPermissionGuard::deny($response);
        }

        $query = $request->getQueryParams();
        $page = \is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        $filters = new AuditLogsFilterState(
            actorType: $this->nullableStr($query, 'actor_type'),
            clientId: $this->nullableInt($query, 'client_id'),
            action: $this->nullableStr($query, 'action'),
            targetType: $this->nullableStr($query, 'target_type'),
            targetId: $this->nullableInt($query, 'target_id'),
        );

        $role = AdminRole::from($this->context->admin()->role);

        $filterQuery = http_build_query(array_filter([
            'actor_type' => $filters->actorType,
            'client_id' => $filters->clientId,
            'action' => $filters->action,
            'target_type' => $filters->targetType,
            'target_id' => $filters->targetId,
        ], static fn (mixed $v): bool => $v !== null));

        return $this->view->render($response, 'audit-logs.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'audit_logs',
            'clients' => [],
            'active_client' => null,
            'screen' => $this->screen->build($filters, $page),
            'filter_query' => $filterQuery,
            'permissions' => array_map(static fn ($p) => $p->value, AdminPermissions::for($role)),
        ]);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function nullableStr(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function nullableInt(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
