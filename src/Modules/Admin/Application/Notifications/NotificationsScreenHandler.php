<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Notifications;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Notifications\Application\ClientNotificationDirectory;
use Gomrok\Modules\Notifications\Application\ClientNotificationFilter;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;

/**
 * Builds the admin Notifications screen (Phase 28 — CLAUDE.md: "Viewing
 * client notification logs" / "Retrying failed client notifications").
 * Mirrors {@see \Gomrok\Modules\Admin\Application\ErrorLogs\ErrorLogsScreenHandler}'s
 * shape.
 */
final readonly class NotificationsScreenHandler
{
    private const PER_PAGE = 50;

    public function __construct(
        private ClientNotificationDirectory $notifications,
        private ClientDirectory $clients,
    ) {
    }

    public function build(NotificationsFilterState $filters, int $page): NotificationsScreenResult
    {
        $status = $filters->status !== 'all' ? $filters->status : null;

        $page = max(1, $page);
        $baseFilter = static fn (int $offset): ClientNotificationFilter => new ClientNotificationFilter(
            clientId: $filters->clientId,
            status: $status,
            purpose: $filters->purpose,
            limit: self::PER_PAGE,
            offset: $offset,
        );

        $total = $this->notifications->countMatching($baseFilter(0));
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $entries = $this->notifications->search($baseFilter(($page - 1) * self::PER_PAGE));
        $deadLetteredCount = $this->notifications->countDeadLettered($filters->clientId);

        return new NotificationsScreenResult(
            array_map($this->toRow(...), $entries),
            $total,
            $deadLetteredCount,
            $page,
            self::PER_PAGE,
            $totalPages,
            $filters,
        );
    }

    private function toRow(ClientNotification $notification): NotificationRow
    {
        return new NotificationRow(
            $notification->id() ?? 0,
            $this->clientLabel($notification->clientId()),
            $notification->targetType()->value,
            $notification->targetId(),
            $notification->purpose(),
            $notification->statusValue(),
            $notification->status()->value,
            $notification->attemptCount(),
            $notification->endpointUrl(),
            $notification->nextAttemptAt()?->format('Y-m-d H:i'),
            $notification->lastAttemptedAt()?->format('Y-m-d H:i'),
            $notification->lastResponseStatus(),
            $notification->lastError(),
            $notification->createdAt()->format('Y-m-d H:i'),
            $notification->status() === ClientNotificationStatus::DeadLettered,
        );
    }

    private function clientLabel(int $clientId): string
    {
        $client = $this->clients->findById($clientId);

        return $client !== null ? $client->name : "Client #{$clientId}";
    }
}
