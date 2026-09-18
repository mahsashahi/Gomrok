<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Notifications\Application\RetryClientNotification\RetryClientNotificationCommand;
use Gomrok\Modules\Notifications\Application\RetryClientNotification\RetryClientNotificationHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/notifications/{notificationId}/retry` (Phase 28) — attempts
 * delivery immediately (synchronously), unlike the cron job, so the operator
 * gets real feedback. Only a `dead_lettered` row is eligible.
 */
final readonly class AdminNotificationRetryAction
{
    public function __construct(
        private AdminContext $context,
        private RetryClientNotificationHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::NotificationsRetry)) {
            return AdminPermissionGuard::deny($response);
        }

        $notificationId = (int) $args['notificationId'];
        $body = AdminForm::body($request);
        $returnQuery = AdminForm::str($body, 'return_query');

        $result = $this->handler->handle(new RetryClientNotificationCommand(
            notificationId: $notificationId,
            actorId: $this->context->admin()->id,
        ));

        $suffix = $returnQuery !== '' ? '&' . $returnQuery : '';
        if ($result->isErr()) {
            return $response->withStatus(302)->withHeader(
                'Location',
                '/admin/notifications?' . http_build_query(['error' => $result->error()->message]) . $suffix,
            );
        }

        $status = $result->value();
        \assert(\is_string($status));
        $message = $status === 'sent' ? 'Retried — delivered successfully.' : "Retried — still failing (status: {$status}).";

        return $response->withStatus(302)->withHeader(
            'Location',
            '/admin/notifications?' . http_build_query(['success' => $message]) . $suffix,
        );
    }
}
