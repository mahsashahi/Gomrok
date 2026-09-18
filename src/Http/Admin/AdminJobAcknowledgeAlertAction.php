<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Jobs\AcknowledgeJobAlert\AcknowledgeJobAlertCommand;
use Gomrok\Modules\Admin\Application\Jobs\AcknowledgeJobAlert\AcknowledgeJobAlertHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/jobs/{jobId}/acknowledge-alert` (Phase 29 Q5, revised) —
 * silences a still-failing recurring job's alert without affecting its
 * schedule or status.
 */
final readonly class AdminJobAcknowledgeAlertAction
{
    public function __construct(
        private AdminContext $context,
        private AcknowledgeJobAlertHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::JobsRetry)) {
            return AdminPermissionGuard::deny($response);
        }

        $jobId = (int) $args['jobId'];
        $body = AdminForm::body($request);
        $returnQuery = AdminForm::str($body, 'return_query');

        $result = $this->handler->handle(new AcknowledgeJobAlertCommand(
            jobId: $jobId,
            actorId: $this->context->admin()->id,
        ));

        $suffix = $returnQuery !== '' ? '&' . $returnQuery : '';
        if ($result->isErr()) {
            return $response->withStatus(302)->withHeader(
                'Location',
                '/admin/jobs?' . http_build_query(['error' => $result->error()->message]) . $suffix,
            );
        }

        return $response->withStatus(302)->withHeader(
            'Location',
            '/admin/jobs?' . http_build_query(['success' => 'Alert acknowledged.']) . $suffix,
        );
    }
}
