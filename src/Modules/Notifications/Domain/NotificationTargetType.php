<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

/**
 * What kind of Gomrok aggregate a {@see ClientNotification} reports on —
 * the polymorphic half of `client_notification_logs.target_type` /
 * `target_id` (no real FK, same shape as `audit_logs`/`error_logs`).
 */
enum NotificationTargetType: string
{
    case Payment = 'payment';
    case Subscription = 'subscription';
}
