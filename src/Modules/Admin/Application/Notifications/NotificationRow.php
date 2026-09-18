<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Notifications;

final readonly class NotificationRow
{
    public function __construct(
        public int $id,
        public string $clientLabel,
        public string $targetType,
        public int $targetId,
        public string $purpose,
        public string $statusValue,
        public string $status,
        public int $attemptCount,
        public string $endpointUrl,
        public ?string $nextAttemptLabel,
        public ?string $lastAttemptedLabel,
        public ?int $lastResponseStatus,
        public ?string $lastError,
        public string $createdLabel,
        public bool $isRetryable,
    ) {
    }
}
