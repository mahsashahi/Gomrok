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
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLinkRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;

/**
 * Resolves the adapter/capabilities/reference a cancel/refund/capture call
 * needs (Phase 24 Q5). A checkout-originated payment's provider account and
 * payment method come from the
 * {@see \Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot}
 * frozen for its checkout attempt — the same routing decision that created
 * the payment in the first place.
 *
 * A subscription renewal charge has no checkout attempt of its own (Phase 26
 * Q2) — instead (Phase 29 Q4) its provider account/payment method are read
 * from the {@see \Gomrok\Modules\Subscriptions\Domain\Subscription} it's
 * linked to via `subscription_payment_links`. Both origins converge on the
 * same downstream capability/adapter/reference resolution — a renewal
 * payment gets exactly the same capability gating and gateway-reference
 * lookup a checkout-originated one does, nothing is bypassed.
 *
 * Reference selection (Q5): prefer the "deeper" reference recorded under
 * {@see GatewayReferenceType::PaymentIntent} — the id
 * {@see \Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter}'s
 * capture/cancel/refund need, and the id
 * {@see \Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal\PayPalAdapter}'s
 * refund needs (both surfaced via
 * {@see \Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus::$paymentIntentReference}
 * and persisted by {@see \Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler}
 * for a checkout-originated payment, or by
 * {@see \Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentHandler}
 * for a renewal-originated one) — falling back to the original
 * {@see GatewayReferenceType::CheckoutSession} reference when no deeper one
 * was ever recorded (Mollie: the same id serves every action, so no deeper
 * reference exists; never applicable to a renewal payment, which has no
 * checkout session of its own).
 */
final readonly class ResolvePaymentActionContext
{
    public function __construct(
        private ProviderRoutingDecisionSnapshotRepository $routingSnapshots,
        private ProviderAccountDirectory $accounts,
        private ProviderCapabilityResolver $capabilityResolver,
        private ProviderAdapterFactory $adapterFactory,
        private GatewayReferenceRepository $gatewayReferences,
        private SubscriptionPaymentLinkRepository $paymentLinks,
        private SubscriptionRepository $subscriptions,
    ) {
    }

    public function forPayment(Payment $payment): ?PaymentActionContext
    {
        $paymentId = $payment->id();
        if ($paymentId === null) {
            return null;
        }

        $origin = $this->resolveOrigin($payment, $paymentId);
        if ($origin === null) {
            return null;
        }
        [$providerAccountId, $method] = $origin;

        $account = $this->accounts->findById($providerAccountId);
        if ($account === null) {
            return null;
        }

        $capabilities = $this->capabilityResolver->resolve($account->providerTypeCode, $method);
        if ($capabilities === null) {
            return null;
        }

        try {
            $adapter = $this->adapterFactory->for($providerAccountId);
        } catch (UnsupportedProviderType) {
            return null;
        }

        $reference = $this->resolveReference($paymentId);
        if ($reference === null) {
            return null;
        }

        return new PaymentActionContext($providerAccountId, $adapter, $capabilities, $reference);
    }

    /**
     * @return array{0: int, 1: ?PaymentMethod}|null
     */
    private function resolveOrigin(Payment $payment, int $paymentId): ?array
    {
        $checkoutAttemptId = $payment->checkoutAttemptId();
        if ($checkoutAttemptId !== null) {
            $routing = $this->routingSnapshots->findByCheckoutAttemptId($checkoutAttemptId);
            if ($routing === null) {
                return null;
            }

            $method = $routing->paymentMethod !== null ? PaymentMethod::tryFrom($routing->paymentMethod) : null;

            return [$routing->providerAccountId, $method];
        }

        $link = $this->paymentLinks->findByPaymentId($paymentId);
        if ($link === null) {
            return null;
        }

        $subscription = $this->subscriptions->findById($link->subscriptionId);
        if ($subscription === null) {
            return null;
        }

        return [$subscription->providerAccountId(), $subscription->paymentMethod()];
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
