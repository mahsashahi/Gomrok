<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application;

use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Modules\Providers\Domain\PaymentMethod;

/**
 * Resolves the adapter/capabilities/reference a cancel/refund/capture call
 * needs (Phase 24 Q5). The provider account and payment method come from the
 * {@see \Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot}
 * frozen for the payment's checkout attempt — the same routing decision that
 * created the payment in the first place.
 *
 * Reference selection (Q5): prefer the "deeper" reference recorded under
 * {@see GatewayReferenceType::PaymentIntent} — the id
 * {@see \Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter}'s
 * capture/cancel/refund need, and the id
 * {@see \Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal\PayPalAdapter}'s
 * refund needs (both surfaced via
 * {@see \Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus::$paymentIntentReference}
 * and persisted by {@see \Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler}) —
 * falling back to the original {@see GatewayReferenceType::CheckoutSession}
 * reference when no deeper one was ever recorded (Mollie: the same id serves
 * every action, so no deeper reference exists).
 *
 * A subscription renewal charge has no checkout attempt at all (Phase 26 Q2)
 * — `forPayment()` returns `null` for one today (cancel/refund/capture on a
 * renewal-originated payment isn't wired up this phase; a future phase could
 * resolve the provider context via `subscription_payment_links` instead).
 */
final readonly class ResolvePaymentActionContext
{
    public function __construct(
        private ProviderRoutingDecisionSnapshotRepository $routingSnapshots,
        private ProviderAccountDirectory $accounts,
        private ProviderCapabilityResolver $capabilityResolver,
        private ProviderAdapterFactory $adapterFactory,
        private GatewayReferenceRepository $gatewayReferences,
    ) {
    }

    public function forPayment(Payment $payment): ?PaymentActionContext
    {
        $checkoutAttemptId = $payment->checkoutAttemptId();
        if ($checkoutAttemptId === null) {
            return null;
        }

        $routing = $this->routingSnapshots->findByCheckoutAttemptId($checkoutAttemptId);
        if ($routing === null) {
            return null;
        }

        $account = $this->accounts->findById($routing->providerAccountId);
        if ($account === null) {
            return null;
        }

        $method = $routing->paymentMethod !== null ? PaymentMethod::tryFrom($routing->paymentMethod) : null;
        $capabilities = $this->capabilityResolver->resolve($account->providerTypeCode, $method);
        if ($capabilities === null) {
            return null;
        }

        try {
            $adapter = $this->adapterFactory->for($routing->providerAccountId);
        } catch (UnsupportedProviderType) {
            return null;
        }

        $paymentId = $payment->id();
        if ($paymentId === null) {
            return null;
        }

        $reference = $this->resolveReference($paymentId);
        if ($reference === null) {
            return null;
        }

        return new PaymentActionContext($routing->providerAccountId, $adapter, $capabilities, $reference);
    }

    private function resolveReference(int $paymentId): ?string
    {
        $deep = null;
        $primary = null;

        foreach ($this->gatewayReferences->forPayment($paymentId) as $reference) {
            if ($reference->referenceType === GatewayReferenceType::PaymentIntent) {
                $deep = $reference;
            } elseif ($reference->referenceType === GatewayReferenceType::CheckoutSession) {
                $primary = $reference;
            }
        }

        return ($deep ?? $primary)?->referenceValue;
    }
}
