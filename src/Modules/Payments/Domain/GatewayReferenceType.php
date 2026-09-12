<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * Generic, provider-agnostic reference kinds (Phase 20 Q4) — deliberately not
 * one case per provider (`stripe_payment_intent`, `mollie_payment`, …), so a
 * new provider or reference kind never needs a schema or enum change.
 */
enum GatewayReferenceType: string
{
    case CheckoutSession = 'checkout_session';
    case PaymentIntent = 'payment_intent';
    case Order = 'order';
    case Transaction = 'transaction';
    case Subscription = 'subscription';
    case Customer = 'customer';
    case Other = 'other';
}
