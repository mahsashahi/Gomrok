<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application\RetryClientNotification;

final readonly class RetryClientNotificationCommand
{
    public function __construct(
        public int $notificationId,
        public ?int $actorId = null,
    ) {
    }
}
