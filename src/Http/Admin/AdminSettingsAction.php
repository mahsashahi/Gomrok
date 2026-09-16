<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/settings` — a neutral placeholder, per `.claude/docs/Phases.md`'s
 * Phase 27 scope note ("Undesigned screens render a neutral titled
 * placeholder"). The sidebar link exists to match the shell design, but no
 * admin-editable settings domain/schema exists anywhere in the codebase —
 * `Settings`/`DatabaseSettings` in `src/Config/` are plain env-var config
 * loaders, not a database-backed, admin-editable model — and CLAUDE.md never
 * lists a settings-management capability among the admin panel's
 * requirements. Deliberately carries no permission gate: there is no data or
 * write action here to protect.
 */
final readonly class AdminSettingsAction
{
    public function __construct(
        private AdminContext $context,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'settings.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'settings',
            'clients' => [],
            'active_client' => null,
        ]);
    }
}
