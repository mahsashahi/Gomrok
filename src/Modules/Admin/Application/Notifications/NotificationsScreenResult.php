<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Notifications;

final readonly class NotificationsScreenResult
{
    /**
     * @param list<NotificationRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
        public int $deadLetteredCount,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public NotificationsFilterState $filters,
    ) {
    }
}
