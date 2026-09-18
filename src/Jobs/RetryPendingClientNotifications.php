<?php

declare(strict_types=1);

namespace Gomrok\Jobs;

use Gomrok\Modules\Notifications\Application\DeliverClientNotification\DeliverClientNotificationHandler;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Psr\Log\LoggerInterface;

/**
 * The cron-invokable delivery/retry job for `client_notification_logs`
 * (Phase 28) — the *only* place that actually attempts delivery; enqueuing
 * (Phase 28's event subscribers) is DB-only and never calls out over the
 * network. A plain invokable, the same "cron now, real queue/worker at
 * Phase 29" shape as {@see RetryPendingWebhookEvents} (Phase 25). Run it
 * from `bin/RetryPendingClientNotifications.php` on a schedule (every
 * minute or so, matching Q4's shortest 1-minute backoff step).
 */
final readonly class RetryPendingClientNotifications
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private ClientNotificationRepository $notifications,
        private DeliverClientNotificationHandler $deliverer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{attempted: int}
     */
    public function __invoke(): array
    {
        $attempted = 0;

        foreach ($this->notifications->findDeliverable(self::BATCH_SIZE) as $notification) {
            ++$attempted;
            $this->deliverer->deliver($notification);
        }

        $summary = ['attempted' => $attempted];
        $this->logger->info('job.retry_pending_client_notifications', $summary);

        return $summary;
    }
}
