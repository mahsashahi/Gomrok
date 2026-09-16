<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\ErrorLogs\SetErrorLogResolution\SetErrorLogResolutionCommand;
use Gomrok\Modules\Admin\Application\ErrorLogs\SetErrorLogResolution\SetErrorLogResolutionHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/error-logs/{errorLogId}/resolution` (Phase 27) — "Mark
 * resolved" / "Reopen". Gated on `error_logs.resolve`, a permission key added
 * for this action since CLAUDE.md's own suggested list omitted one despite
 * the schema already being designed for it — see `AdminPermission`'s
 * docblock on that case.
 */
final readonly class AdminErrorLogResolutionAction
{
    public function __construct(
        private AdminContext $context,
        private SetErrorLogResolutionHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ErrorLogsResolve)) {
            return AdminPermissionGuard::deny($response);
        }

        $errorLogId = (int) $args['errorLogId'];
        $body = AdminForm::body($request);
        $resolved = AdminForm::str($body, 'action') === 'resolve';
        $returnQuery = AdminForm::str($body, 'return_query');

        $result = $this->handler->handle(new SetErrorLogResolutionCommand(
            errorLogId: $errorLogId,
            resolved: $resolved,
            actorId: $this->context->admin()->id,
        ));

        $suffix = $returnQuery !== '' ? '&' . $returnQuery : '';
        if ($result->isErr()) {
            return $response->withStatus(302)->withHeader(
                'Location',
                '/admin/error-logs?' . http_build_query(['error' => $result->error()->message]) . $suffix,
            );
        }

        return $response->withStatus(302)->withHeader(
            'Location',
            '/admin/error-logs?' . http_build_query(['success' => $resolved ? 'Marked resolved.' : 'Reopened.']) . $suffix,
        );
    }
}
