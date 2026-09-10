<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * The fixed vocabulary of provider capability flags (Phase 8 Q1 — enum is the
 * source of truth; `provider_capabilities` is a seeded mirror). Purchase types
 * (`one_time_payment`, `recurring_payment`, `auto_charge`, `subscription`) are a
 * **separate** concept — see {@see PurchaseType} — and are deliberately not in
 * this enum.
 *
 * Capability gates in code use these cases; adding one is a code change plus a
 * `ProviderCapabilitiesSeeder` re-run.
 */
enum Capability: string
{
    // payment
    case HostedCheckout = 'hosted_checkout';
    case RedirectPayment = 'redirect_payment';
    case EmbeddedPaymentForm = 'embedded_payment_form';
    case CardTokenization = 'card_tokenization';
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Cancel = 'cancel';

    // refund
    case Refund = 'refund';
    case PartialRefund = 'partial_refund';

    // subscription management
    case SubscriptionCancel = 'subscription_cancel';
    case SubscriptionPause = 'subscription_pause';
    case SubscriptionResume = 'subscription_resume';
    case CustomerPortal = 'customer_portal';
    case BillingPortal = 'billing_portal';
    case Invoice = 'invoice';

    // security
    case ThreeDSecure = 'three_d_secure';

    // operational
    case Webhook = 'webhook';
    case ReturnUrl = 'return_url';
    case ManualStatusPolling = 'manual_status_polling';

    public function group(): CapabilityGroup
    {
        return match ($this) {
            self::HostedCheckout, self::RedirectPayment, self::EmbeddedPaymentForm,
            self::CardTokenization, self::Authorization, self::Capture, self::Cancel
                => CapabilityGroup::Payment,
            self::Refund, self::PartialRefund
                => CapabilityGroup::Refund,
            self::SubscriptionCancel, self::SubscriptionPause, self::SubscriptionResume,
            self::CustomerPortal, self::BillingPortal, self::Invoice
                => CapabilityGroup::Subscription,
            self::ThreeDSecure
                => CapabilityGroup::Security,
            self::Webhook, self::ReturnUrl, self::ManualStatusPolling
                => CapabilityGroup::Operational,
        };
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
