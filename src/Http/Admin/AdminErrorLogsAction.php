<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\ErrorLogs\ErrorLogsFilterState;
use Gomrok\Modules\Admin\Application\ErrorLogs\ErrorLogsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/error-logs` (Phase 27 — CLAUDE.md: "Viewing error logs" /
 * "Basic reconciliation and debugging"). Defaults to showing only unresolved
 * rows — an operator triage surface should open on what still needs
 * attention, not the full history.
 */
final readonly class AdminErrorLogsAction
{
    public function __construct(
        private AdminContext $context,
        private ErrorLogsScreenHandler $screen,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ErrorLogsView)) {
            return AdminPermissionGuard::deny($response);
        }

        $query = $request->getQueryParams();
        $page = \is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        $filters = new ErrorLogsFilterState(
            level: $this->nullableStr($query, 'level'),
            source: $this->nullableStr($query, 'source'),
            clientId: $this->nullableInt($query, 'client_id'),
            resolved: \in_array($query['resolved'] ?? null, ['all', 'resolved', 'unresolved'], true) ? $query['resolved'] : 'unresolved',
        );

        $role = AdminRole::from($this->context->admin()->role);

        $filterQuery = http_build_query(array_filter([
            'level' => $filters->level,
            'source' => $filters->source,
            'client_id' => $filters->clientId,
            'resolved' => $filters->resolved !== 'unresolved' ? $filters->resolved : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $this->view->render($response, 'error-logs.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'error_logs',
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
