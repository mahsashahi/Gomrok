<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclaration;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * In-memory {@see ProviderTypeDeclarations} for resolver tests. Ships the four
 * known providers' shapes so a test can cover the Phase 8 exit matrix (Stripe vs
 * Ziraat; Mollie card vs PayPal) without a database.
 */
final class InMemoryProviderTypeDeclarations implements ProviderTypeDeclarations
{
    /** @var array<string, ProviderTypeDeclaration> */
    private array $byCode = [];

    public function add(ProviderTypeDeclaration $declaration): void
    {
        $this->byCode[$declaration->providerTypeCode] = $declaration;
    }

    public function findByCode(string $providerTypeCode): ?ProviderTypeDeclaration
    {
        return $this->byCode[$providerTypeCode] ?? null;
    }

    public function all(): array
    {
        return array_values($this->byCode);
    }

    public static function withKnownProviders(): self
    {
        $self = new self();

        $self->add(new ProviderTypeDeclaration('stripe', [
            PurchaseType::OneTimePayment, PurchaseType::RecurringPayment,
            PurchaseType::AutoCharge, PurchaseType::Subscription,
        ], ProviderCapabilities::of(
            Capability::HostedCheckout,
            Capability::EmbeddedPaymentForm,
            Capability::CardTokenization,
            Capability::Authorization,
            Capability::Capture,
            Capability::Cancel,
            Capability::Refund,
            Capability::PartialRefund,
            Capability::SubscriptionCancel,
            Capability::SubscriptionPause,
            Capability::SubscriptionResume,
            Capability::CustomerPortal,
            Capability::BillingPortal,
            Capability::Invoice,
            Capability::ThreeDSecure,
            Capability::Webhook,
            Capability::ReturnUrl,
        )));

        $self->add(new ProviderTypeDeclaration('paypal', [
            PurchaseType::OneTimePayment, PurchaseType::RecurringPayment, PurchaseType::Subscription,
        ], ProviderCapabilities::of(
            Capability::HostedCheckout,
            Capability::RedirectPayment,
            Capability::Authorization,
            Capability::Capture,
            Capability::Cancel,
            Capability::Refund,
            Capability::PartialRefund,
            Capability::SubscriptionCancel,
            Capability::Webhook,
            Capability::ReturnUrl,
        )));

        // Mollie — full at the type level; per-method narrowing via MethodCapabilityRules.
        $self->add(new ProviderTypeDeclaration('mollie', [
            PurchaseType::OneTimePayment, PurchaseType::RecurringPayment, PurchaseType::Subscription,
        ], ProviderCapabilities::of(
            Capability::HostedCheckout,
            Capability::RedirectPayment,
            Capability::Refund,
            Capability::PartialRefund,
            Capability::SubscriptionCancel,
            Capability::CustomerPortal,
            Capability::ThreeDSecure,
            Capability::Webhook,
            Capability::ReturnUrl,
        )));

        // Ziraat — charge-only, no API product model, no subscriptions / auto-charge.
        $self->add(new ProviderTypeDeclaration('ziraat', [
            PurchaseType::OneTimePayment,
        ], ProviderCapabilities::of(
            Capability::HostedCheckout,
            Capability::RedirectPayment,
            Capability::ThreeDSecure,
            Capability::Webhook,
            Capability::ReturnUrl,
            Capability::ManualStatusPolling,
        )));

        return $self;
    }
}
