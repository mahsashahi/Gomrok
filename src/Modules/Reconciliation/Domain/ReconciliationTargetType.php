<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Domain;

/**
 * What kind of Gomrok aggregate a {@see ReconciliationFinding} reports
 * drift on — the polymorphic half of `reconciliation_findings.target_type` /
 * `target_id` (no real FK, same shape as `client_notification_logs`).
 */
enum ReconciliationTargetType: string
{
    case Payment = 'payment';
    case Subscription = 'subscription';
}
