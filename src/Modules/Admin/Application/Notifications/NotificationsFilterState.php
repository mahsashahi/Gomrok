<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Notifications;

final readonly class NotificationsFilterState
{
    public function __construct(
        public ?int $clientId = null,
        /** `all` | `pending` | `sent` | `dead_lettered`. */
        public string $status = 'all',
        public ?string $purpose = null,
    ) {
    }
}
