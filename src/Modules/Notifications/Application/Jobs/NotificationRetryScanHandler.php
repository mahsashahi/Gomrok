<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application\Jobs;

use Gomrok\Modules\Notifications\Application\DeliverClientNotification\DeliverClientNotificationHandler;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;

/**
 * The `jobs`-table version of
 * {@see \Gomrok\Jobs\RetryPendingClientNotifications} (Phase 28), migrated
 * onto the unified queue (Phase 29 Q1). The original standalone job/`bin/`
 * script stays operational until `bin/Worker.php` exists (Q5, still pending).
 */
final readonly class NotificationRetryScanHandler implements JobHandler
{
    private const BATCH_SIZE = 100;
    private const RECURRENCE_MINUTES = 1;

    public function __construct(
        private ClientNotificationRepository $notifications,
        private DeliverClientNotificationHandler $deliverer,
    ) {
    }

    public function type(): string
    {
        return 'notification_retry_scan';
    }

    public function recurrenceIntervalMinutes(): int
    {
        return self::RECURRENCE_MINUTES;
    }

    public function handle(Job $job): JobRunResult
    {
        $attempted = 0;
        foreach ($this->notifications->findDeliverable(self::BATCH_SIZE) as $notification) {
            ++$attempted;
            $this->deliverer->deliver($notification);
        }

        return JobRunResult::success(['attempted' => $attempted]);
    }
}
