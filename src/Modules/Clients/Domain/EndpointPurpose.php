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
    /**
     * Where Gomrok's own return endpoint (Phase 24 Q2/Q3) 302-redirects the
     * customer's browser after verifying a completed payment with the
     * provider — the client's own success page, never a provider or
     * per-request URL.
     */
    case CheckoutSuccess = 'checkout_success';
    /** Same as {@see CheckoutSuccess}, for a canceled/failed outcome. */
    case CheckoutCancel = 'checkout_cancel';
}
