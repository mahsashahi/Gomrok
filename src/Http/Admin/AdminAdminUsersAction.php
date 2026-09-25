<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\AdminUsers\AdminUsersScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/admin-users` (Phase 27) — a flat table of every admin panel
 * user, matching {@see AdminClientsAction}'s "not scoped to the active
 * client" shape (admin accounts are global, not per-client).
 *
 * {@see render()} is also the reopen target for every Admin Users write
 * action's validation failure (`.claude/docs/Ui.md`'s validation-preserving
 * forms rule): instead of redirecting and losing the submitted form, a
 * write action calls {@see reopen()} with an {@see AdminModalReopen} so the
 * exact same screen re-renders with the failed modal reopened and the
 * submitted values still in it.
 */
final readonly class AdminAdminUsersAction
{
    public function __construct(
        private AdminContext $context,
        private AdminUsersScreenHandler $screen,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, null);
    }

    public function render(ServerRequestInterface $request, ResponseInterface $response, ?AdminModalReopen $reopen): ResponseInterface
    {
        $query = $request->getQueryParams();
        $error = \is_string($query['error'] ?? null) ? $query['error'] : null;
        $success = \is_string($query['success'] ?? null) ? $query['success'] : null;
        $role = AdminRole::from($this->context->admin()->role);

        return $this->view->render($response, 'admin-users.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'admin_users',
            'clients' => [],
            'active_client' => null,
            'screen' => $this->screen->build($this->context->admin()->id),
            'error' => $error,
            'success' => $success,
            'permissions' => array_map(static fn ($p) => $p->value, AdminPermissions::for($role)),
            'reopen_modal' => $reopen,
        ], $reopen !== null ? 422 : 200);
    }

    /**
     * A write action's validation-failure return: re-renders this screen
     * with the failed modal reopened. This screen carries no `?tab=`/
     * selection query params (a flat, unscoped table), so `$queryParams` is
     * always empty in practice — kept as a parameter only to mirror the
     * other screens' identical `reopen()` shape.
     *
     * @param array<string, string|int|null> $queryParams
     */
    public function reopen(ServerRequestInterface $request, ResponseInterface $response, array $queryParams, AdminModalReopen $reopen): ResponseInterface
    {
        $query = array_filter($queryParams, static fn (mixed $v): bool => $v !== null);
        $uri = $request->getUri()->withQuery(http_build_query($query));

        return $this->render($request->withUri($uri), $response, $reopen);
    }
}
