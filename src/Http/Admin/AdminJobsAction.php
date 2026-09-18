<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Jobs\JobsFilterState;
use Gomrok\Modules\Admin\Application\Jobs\JobsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/jobs` (Phase 29 — CLAUDE.md: "Retrying failed jobs where
 * safe"). The `jobs` table has no `client_id` (Phase 29 Q1: a unified,
 * system-wide queue), so unlike most admin screens this one carries no
 * client-scoping.
 */
final readonly class AdminJobsAction
{
    public function __construct(
        private AdminContext $context,
        private JobsScreenHandler $screen,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::JobsView)) {
            return AdminPermissionGuard::deny($response);
        }

        $query = $request->getQueryParams();
        $page = \is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        $filters = new JobsFilterState(
            status: \in_array($query['status'] ?? null, ['all', 'pending', 'processing', 'done', 'failed', 'dead_lettered'], true) ? $query['status'] : 'all',
            type: $this->nullableStr($query, 'type'),
        );

        $role = AdminRole::from($this->context->admin()->role);

        $filterQuery = http_build_query(array_filter([
            'status' => $filters->status !== 'all' ? $filters->status : null,
            'type' => $filters->type,
        ], static fn (mixed $v): bool => $v !== null));

        return $this->view->render($response, 'jobs.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'jobs',
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
}
