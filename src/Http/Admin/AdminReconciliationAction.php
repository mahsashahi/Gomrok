<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Reconciliation\ReconciliationFilterState;
use Gomrok\Modules\Admin\Application\Reconciliation\ReconciliationScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/reconciliation` (Phase 29 — CLAUDE.md: "Basic reconciliation
 * and debugging"). Defaults to `open` findings only — an operator triage
 * view where the default should be "what needs attention," unlike
 * Notifications' show-everything default.
 */
final readonly class AdminReconciliationAction
{
    public function __construct(
        private AdminContext $context,
        private ReconciliationScreenHandler $screen,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ReconciliationView)) {
            return AdminPermissionGuard::deny($response);
        }

        $query = $request->getQueryParams();
        $page = \is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        $filters = new ReconciliationFilterState(
            clientId: $this->nullableInt($query, 'client_id'),
            resolution: \in_array($query['resolution'] ?? null, ['all', 'open', 'resolved'], true) ? $query['resolution'] : 'open',
        );

        $role = AdminRole::from($this->context->admin()->role);

        $filterQuery = http_build_query(array_filter([
            'client_id' => $filters->clientId,
            'resolution' => $filters->resolution !== 'open' ? $filters->resolution : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $this->view->render($response, 'reconciliation.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'reconciliation',
            'clients' => [],
            'active_client' => null,
            'screen' => $this->screen->build($filters, $page),
            'filter_query' => $filterQuery,
            'success' => $this->nullableStr($query, 'success'),
            'error' => $this->nullableStr($query, 'error'),
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
