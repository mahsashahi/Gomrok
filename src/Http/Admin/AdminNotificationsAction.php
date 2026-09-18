<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Notifications\NotificationsFilterState;
use Gomrok\Modules\Admin\Application\Notifications\NotificationsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/notifications` (Phase 28 — CLAUDE.md: "Viewing client
 * notification logs" / "Retrying failed client notifications"). Defaults to
 * showing every status, not just `dead_lettered` — an operator triage view
 * for a system-wide table still benefits from seeing the healthy majority
 * for context, unlike Error Logs' "only unresolved" default.
 */
final readonly class AdminNotificationsAction
{
    public function __construct(
        private AdminContext $context,
        private NotificationsScreenHandler $screen,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::NotificationsView)) {
            return AdminPermissionGuard::deny($response);
        }

        $query = $request->getQueryParams();
        $page = \is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1;

        $filters = new NotificationsFilterState(
            clientId: $this->nullableInt($query, 'client_id'),
            status: \in_array($query['status'] ?? null, ['all', 'pending', 'sent', 'dead_lettered'], true) ? $query['status'] : 'all',
            purpose: $this->nullableStr($query, 'purpose'),
        );

        $role = AdminRole::from($this->context->admin()->role);

        $filterQuery = http_build_query(array_filter([
            'client_id' => $filters->clientId,
            'status' => $filters->status !== 'all' ? $filters->status : null,
            'purpose' => $filters->purpose,
        ], static fn (mixed $v): bool => $v !== null));

        return $this->view->render($response, 'notifications.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'notifications',
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
