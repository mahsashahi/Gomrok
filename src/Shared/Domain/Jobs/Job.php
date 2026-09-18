<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain\Jobs;

use DateTimeImmutable;

/**
 * One row in the unified background-work queue (Phase 29 Q1). `type` is a
 * fixed, code-defined set — no lookup table. `payload` is null for every
 * recurring scan type this phase introduces; it exists for a future
 * genuinely one-off, enqueued task. `id` is null until persisted.
 *
 * Health tracking (Phase 29 Q5, revised — user-specified): a recurring job
 * never dead-letters or gets escalating backoff from repeated failure — it
 * always reschedules at its handler's fixed interval, forever — but its
 * health is tracked and surfaced instead of failing silently.
 * `consecutiveFailures` resets to `0` on success and, once it crosses
 * {@see self::ALERT_THRESHOLD}, sets `alertedAt` exactly once per failure
 * episode (a further consecutive failure doesn't re-set it — avoids alert
 * spam); an admin can {@see self::acknowledgeAlert()} to silence it without
 * it re-firing before the next failure, and only a real recovery (a
 * success) clears `alertedAt` and the acknowledgement together, starting
 * the next episode fresh. `totalFailures` never resets — a lifetime count
 * for at-a-glance reliability on the admin Jobs screen.
 */
final class Job
{
    /**
     * Not per-job-type or DB-configurable — finer-grained tuning wasn't
     * asked for; a single constant keeps this proportionate to what was
     * requested.
     */
    private const ALERT_THRESHOLD = 3;

    private function __construct(
        private ?int $id,
        private readonly string $type,
        private readonly ?string $payload,
        private JobStatus $status,
        private int $attempts,
        private int $consecutiveFailures,
        private int $totalFailures,
        private ?DateTimeImmutable $lastFailedAt,
        private ?DateTimeImmutable $lastSuccessAt,
        private ?DateTimeImmutable $alertedAt,
        private ?DateTimeImmutable $alertAcknowledgedAt,
        private ?int $alertAcknowledgedBy,
        private DateTimeImmutable $runAt,
        private ?DateTimeImmutable $lockedAt,
        private ?string $lockedBy,
        private ?string $lastError,
        private ?string $lastResult,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function schedule(string $type, ?string $payload, DateTimeImmutable $runAt, DateTimeImmutable $now): self
    {
        return new self(null, $type, $payload, JobStatus::Pending, 0, 0, 0, null, null, null, null, null, $runAt, null, null, null, null, $now, null);
    }

    public static function fromStorage(
        int $id,
        string $type,
        ?string $payload,
        JobStatus $status,
        int $attempts,
        int $consecutiveFailures,
        int $totalFailures,
        ?DateTimeImmutable $lastFailedAt,
        ?DateTimeImmutable $lastSuccessAt,
        ?DateTimeImmutable $alertedAt,
        ?DateTimeImmutable $alertAcknowledgedAt,
        ?int $alertAcknowledgedBy,
        DateTimeImmutable $runAt,
        ?DateTimeImmutable $lockedAt,
        ?string $lockedBy,
        ?string $lastError,
        ?string $lastResult,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $type,
            $payload,
            $status,
            $attempts,
            $consecutiveFailures,
            $totalFailures,
            $lastFailedAt,
            $lastSuccessAt,
            $alertedAt,
            $alertAcknowledgedAt,
            $alertAcknowledgedBy,
            $runAt,
            $lockedAt,
            $lockedBy,
            $lastError,
            $lastResult,
            $createdAt,
            $updatedAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    /** Claims the job for one worker, marking it in-flight. */
    public function claim(string $lockedBy, DateTimeImmutable $now): void
    {
        $this->status = JobStatus::Processing;
        ++$this->attempts;
        $this->lockedAt = $now;
        $this->lockedBy = $lockedBy;
        $this->updatedAt = $now;
    }

    /** The run succeeded. `nextRunAt` reschedules a recurring job; null leaves it terminal. */
    public function recordSuccess(?string $resultJson, ?DateTimeImmutable $nextRunAt, DateTimeImmutable $now): void
    {
        $this->status = JobStatus::Done;
        $this->lastResult = $resultJson;
        $this->lastError = null;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->updatedAt = $now;

        $this->consecutiveFailures = 0;
        $this->lastSuccessAt = $now;
        $this->alertedAt = null;
        $this->alertAcknowledgedAt = null;
        $this->alertAcknowledgedBy = null;

        if ($nextRunAt !== null) {
            $this->status = JobStatus::Pending;
            $this->runAt = $nextRunAt;
        }
    }

    /**
     * The run failed. `nextRunAt` reschedules a recurring job regardless of
     * the failure — a recurring job never dead-letters or backs off from
     * repeated failure (Q5 revised); it always keeps trying at its fixed
     * interval. Health is tracked instead: see the class docblock.
     */
    public function recordFailure(string $error, ?DateTimeImmutable $nextRunAt, DateTimeImmutable $now): void
    {
        $this->status = JobStatus::Failed;
        $this->lastError = $error;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->updatedAt = $now;

        ++$this->consecutiveFailures;
        ++$this->totalFailures;
        $this->lastFailedAt = $now;
        if ($this->alertedAt === null && $this->consecutiveFailures >= self::ALERT_THRESHOLD) {
            $this->alertedAt = $now;
        }

        if ($nextRunAt !== null) {
            $this->status = JobStatus::Pending;
            $this->runAt = $nextRunAt;
        }
    }

    /** A one-off job that has exhausted its attempts. */
    public function deadLetter(string $error, DateTimeImmutable $now): void
    {
        $this->status = JobStatus::DeadLettered;
        $this->lastError = $error;
        $this->lockedAt = null;
        $this->lockedBy = null;
        $this->updatedAt = $now;
    }

    /**
     * An admin silencing a still-failing job's alert. A no-op (returns
     * `false`) when there's nothing open to acknowledge — no alert raised
     * yet, or already acknowledged — mirroring the idempotent
     * mark-resolved convention used elsewhere (`ReconciliationFinding`,
     * `ErrorLogRecord`). `alertedAt` deliberately stays set: acknowledging
     * silences the *current* episode without letting the next consecutive
     * failure immediately re-raise it; only a real recovery clears it.
     */
    public function acknowledgeAlert(int $adminUserId, DateTimeImmutable $now): bool
    {
        if ($this->alertedAt === null || $this->alertAcknowledgedAt !== null) {
            return false;
        }

        $this->alertAcknowledgedAt = $now;
        $this->alertAcknowledgedBy = $adminUserId;
        $this->updatedAt = $now;

        return true;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function payload(): ?string
    {
        return $this->payload;
    }

    public function status(): JobStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function totalFailures(): int
    {
        return $this->totalFailures;
    }

    public function lastFailedAt(): ?DateTimeImmutable
    {
        return $this->lastFailedAt;
    }

    public function lastSuccessAt(): ?DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function alertedAt(): ?DateTimeImmutable
    {
        return $this->alertedAt;
    }

    public function alertAcknowledgedAt(): ?DateTimeImmutable
    {
        return $this->alertAcknowledgedAt;
    }

    public function alertAcknowledgedBy(): ?int
    {
        return $this->alertAcknowledgedBy;
    }

    /** There's an open alert for the current failure episode, acknowledged or not. */
    public function hasOpenAlert(): bool
    {
        return $this->alertedAt !== null;
    }

    /** There's an open alert an admin has not yet acknowledged. */
    public function isAlertUnacknowledged(): bool
    {
        return $this->alertedAt !== null && $this->alertAcknowledgedAt === null;
    }

    public function runAt(): DateTimeImmutable
    {
        return $this->runAt;
    }

    public function lockedAt(): ?DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function lockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastResult(): ?string
    {
        return $this->lastResult;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
