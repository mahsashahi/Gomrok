<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs;

final readonly class JobRow
{
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public int $attempts,
        public string $runAtLabel,
        public ?string $lastAttemptLabel,
        public ?string $lastResultJson,
        public ?string $lastError,
        public bool $isRunnableNow,
        public int $consecutiveFailures,
        public int $totalFailures,
        public ?string $lastFailedAtLabel,
        public ?string $lastSuccessAtLabel,
        public bool $hasOpenAlert,
        public bool $isAlertUnacknowledged,
        public ?string $alertedAtLabel,
        public ?string $alertAcknowledgedByLabel,
    ) {
    }
}
