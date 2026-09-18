<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

/**
 * Lifecycle of one outbound client callback (Phase 28 Q4, user-specified):
 * `pending` -> `sent` | `dead_lettered`. `pending` covers both "never
 * attempted yet" and "failed, will retry" — the same double meaning
 * `WebhookEventStatus::RetryPending` doesn't need because webhooks track
 * `received` separately; here there's nothing to distinguish, so one status
 * covers both.
 */
enum ClientNotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case DeadLettered = 'dead_lettered';
}
