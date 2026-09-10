<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Idempotency;

/**
 * Lifecycle of an idempotency-key row.
 *
 * - `Processing` — a request holding this key is in flight; a concurrent retry
 *   gets a 409.
 * - `Done` — the original request completed; a retry replays the referenced
 *   entity's current state.
 * - `Failed` — the original request threw; the key is free to be reclaimed by a
 *   fresh attempt.
 */
enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
