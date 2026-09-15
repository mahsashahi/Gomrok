<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Domain;

/**
 * The lifecycle of one stored inbound webhook (Phase 25 Q3, user-specified):
 * `received` -> `processing` -> `processed` | `retry_pending` -> `failed`.
 *
 * `retry_pending` and `failed` both mean "the last attempt did not succeed" —
 * `retry_pending` means the cron retry job will pick it up again;
 * `failed` means either `max_attempts` was exceeded or the failure was a
 * definitive domain-rule rejection that retrying can never change.
 */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case RetryPending = 'retry_pending';
    case Failed = 'failed';
}
