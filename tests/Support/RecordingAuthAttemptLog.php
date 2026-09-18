<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Application\Authenticate\AuthAttempt;
use Gomrok\Modules\Clients\Application\Authenticate\AuthAttemptLog;
use Gomrok\Modules\Clients\Domain\AuthFailureReason;
use RuntimeException;

final class RecordingAuthAttemptLog implements AuthAttemptLog
{
    /** @var list<AuthAttempt> */
    public array $attempts = [];

    /** @var list<DateTimeImmutable> parallel to $attempts */
    private array $recordedAt = [];

    public function record(AuthAttempt $attempt, DateTimeImmutable $at): void
    {
        $this->attempts[] = $attempt;
        $this->recordedAt[] = $at;
    }

    public function countFailedSince(string $keyId, DateTimeImmutable $since): int
    {
        $count = 0;
        foreach ($this->attempts as $i => $attempt) {
            if ($attempt->outcome === AuthAttempt::OUTCOME_FAILURE && $attempt->keyId === $keyId && $this->recordedAt[$i] >= $since) {
                ++$count;
            }
        }

        return $count;
    }

    public function last(): AuthAttempt
    {
        if ($this->attempts === []) {
            throw new RuntimeException('No auth attempts were recorded.');
        }

        return $this->attempts[array_key_last($this->attempts)];
    }

    public function lastReason(): AuthFailureReason
    {
        return $this->last()->reason;
    }

    public function lastOutcome(): string
    {
        return $this->last()->outcome;
    }
}
