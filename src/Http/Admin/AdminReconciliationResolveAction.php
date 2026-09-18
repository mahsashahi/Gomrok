<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Reconciliation\Application\ResolveReconciliationFinding\ResolveReconciliationFindingCommand;
use Gomrok\Modules\Reconciliation\Application\ResolveReconciliationFinding\ResolveReconciliationFindingHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/reconciliation/{findingId}/resolve` (Phase 29) — marks a
 * drift finding reviewed. Idempotent: resolving an already-resolved finding
 * is a no-op, not an error.
 */
final readonly class AdminReconciliationResolveAction
{
    public function __construct(
        private AdminContext $context,
        private ResolveReconciliationFindingHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ReconciliationResolve)) {
            return AdminPermissionGuard::deny($response);
        }

        $findingId = (int) $args['findingId'];
        $body = AdminForm::body($request);
        $returnQuery = AdminForm::str($body, 'return_query');

        $result = $this->handler->handle(new ResolveReconciliationFindingCommand(
            findingId: $findingId,
            actorId: $this->context->admin()->id,
        ));

        $suffix = $returnQuery !== '' ? '&' . $returnQuery : '';
        if ($result->isErr()) {
            return $response->withStatus(302)->withHeader(
                'Location',
                '/admin/reconciliation?' . http_build_query(['error' => $result->error()->message]) . $suffix,
            );
        }

        return $response->withStatus(302)->withHeader(
            'Location',
            '/admin/reconciliation?' . http_build_query(['success' => 'Finding marked resolved.']) . $suffix,
        );
    }
}
