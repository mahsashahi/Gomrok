<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * What kind of status update a client callback URL receives. Extended as
 * Notifications (Phase 24) grows; a client registers at most one active URL per
 * purpose.
 */
enum EndpointPurpose: string
{
    case PaymentStatus = 'payment_status';
    case SubscriptionStatus = 'subscription_status';
    case RefundStatus = 'refund_status';
}
