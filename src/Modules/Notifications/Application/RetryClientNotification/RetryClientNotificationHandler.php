<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application\RetryClientNotification;

use Gomrok\Modules\Notifications\Application\DeliverClientNotification\DeliverClientNotificationHandler;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * `notifications.retry` (Phase 28) — an operator retrying a `dead_lettered`
 * row. Unlike the cron job, this attempts delivery immediately (synchronously,
 * within the admin request) so the operator gets real feedback rather than
 * "queued, check back later." Only `dead_lettered` rows are eligible — a
 * still-`pending` row is already going to be retried by the cron job, and a
 * `sent` row needs no retry.
 */
final readonly class RetryClientNotificationHandler
{
    public function __construct(
        private ClientNotificationRepository $notifications,
        private DeliverClientNotificationHandler $deliverer,
        private AuditLogWriter $audit,
        private ClockInterface $clock,
    ) {
    }

    public function handle(RetryClientNotificationCommand $command): Result
    {
        $notification = $this->notifications->findById($command->notificationId);
        if ($notification === null) {
            return Result::err(DomainError::notFound('client_notification.not_found', "Client notification {$command->notificationId} was not found."));
        }

        if ($notification->status() !== ClientNotificationStatus::DeadLettered) {
            return Result::err(DomainError::conflict(
                'client_notification.not_dead_lettered',
                'Only a dead-lettered notification can be manually retried.',
                ['status' => $notification->status()->value],
            ));
        }

        $notification->retryFromDeadLetter($this->clock->now());
        $this->deliverer->deliver($notification);

        $entry = $command->actorId !== null
            ? AuditEntry::forAdminUser($command->actorId, $notification->clientId(), 'client_notification.retried')
            : AuditEntry::forSystem('client_notification.retried', $notification->clientId());
        $this->audit->record(
            $entry->withTarget('client_notification', $command->notificationId)
                ->withContext(['result_status' => $notification->status()->value]),
        );

        return Result::ok($notification->status()->value);
    }
}
