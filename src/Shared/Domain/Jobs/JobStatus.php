<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain\Jobs;

/**
 * Lifecycle of one `jobs` row (Phase 29 Q1). `pending` covers "never run yet"
 * and "due for its next recurring run" — the same double meaning
 * `ClientNotificationStatus::Pending` already carries. `dead_lettered` exists
 * for a future genuinely one-off job that exhausts its attempts; every
 * recurring job type this phase introduces always reschedules regardless of
 * outcome, so it only ever reports `done` or `failed` for its last run.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';
}
