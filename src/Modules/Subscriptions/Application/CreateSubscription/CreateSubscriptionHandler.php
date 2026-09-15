<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\CreateSubscription;

use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\SubscriptionAuditSnapshot;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEvent;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEventRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLink;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLinkRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Creates the `Subscription` record once its originating checkout attempt is
 * `Confirmed` (Phase 26 Q1) — called by `ReconcileCheckoutStatusHandler`
 * right after it converts the same attempt into the first `Payment`, which
 * this handler links via `subscription_payment_links` (Q2). Idempotent by
 * `checkout_attempt_id`: a repeat call returns the existing subscription
 * unchanged, the same pattern `CreatePaymentHandler` already uses.
 *
 * Trial terms come from the package's own resolved purchase capability
 * (`PackagePurchaseCapabilityResolver`, Phase 12) for the attempt's country —
 * not from the request, so a client can't grant itself an undeclared trial.
 */
final readonly class CreateSubscriptionHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private ProviderRoutingDecisionSnapshotRepository $routingSnapshots,
        private ResolveCheckoutPayableAmount $payableAmount,
        private PackagePurchaseCapabilityResolver $packageCapabilities,
        private SubscriptionRepository $subscriptions,
        private SubscriptionEventRepository $subscriptionEvents,
        private SubscriptionPaymentLinkRepository $paymentLinks,
        private GatewayReferenceRepository $gatewayReferences,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreateSubscriptionCommand $command): Result
    {
        $existing = $this->subscriptions->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($existing !== null) {
            $existingId = $existing->id();
            \assert($existingId !== null);

            return Result::ok(new CreateSubscriptionResult($existingId, $existing->status()->value));
        }

        $attempt = $this->attempts->findById($command->checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$command->checkoutAttemptId} was not found for this client."));
        }

        $clientUserRef = $attempt->clientUserRef();
        if ($clientUserRef === null || trim($clientUserRef) === '') {
            return Result::err(DomainError::validation('subscription.client_user_ref_required', 'A subscription requires a client_user_ref.'));
        }

        $interval = $attempt->subscriptionInterval();
        if ($interval === null) {
            return Result::err(DomainError::validation('subscription.interval_missing', 'The checkout attempt has no subscription interval.'));
        }

        $amount = $this->payableAmount->forCheckoutAttempt($command->checkoutAttemptId);
        if ($amount === null) {
            return Result::err(DomainError::validation('subscription.pricing_not_resolved', 'The checkout attempt has no resolved pricing decision.'));
        }

        $routing = $this->routingSnapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($routing === null) {
            return Result::err(DomainError::validation('subscription.provider_routing_missing', 'The checkout attempt has no resolved provider routing decision.'));
        }

        $capability = $this->packageCapabilities->forId($attempt->packageId(), $attempt->country())->for(PurchaseType::Subscription);
        $hasTrial = $capability !== null && $capability->hasTrial;
        $trialDays = $capability?->trialDays;

        $now = $this->clock->now();
        $subscription = Subscription::create(
            $command->clientId,
            $clientUserRef,
            $command->checkoutAttemptId,
            $attempt->packageId(),
            $routing->providerAccountId,
            $amount->currencyCode,
            $amount->amountMinor,
            $attempt->paymentMethod(),
            $interval,
            $hasTrial,
            $trialDays,
            $now,
        );

        $this->transactions->run(function () use ($subscription, $command, $now): void {
            $this->subscriptions->save($subscription);
            $subscriptionId = $subscription->id();
            \assert($subscriptionId !== null);

            $this->paymentLinks->save(SubscriptionPaymentLink::link($subscriptionId, $command->paymentId, null, null, $now));
            $this->subscriptionEvents->save(SubscriptionEvent::record($subscriptionId, 'created', null, null, $now));

            if ($command->subscriptionProviderReference !== null) {
                $this->gatewayReferences->save(GatewayReference::forSubscription(
                    $command->clientId,
                    $subscription->providerAccountId(),
                    GatewayReferenceType::Subscription,
                    $command->subscriptionProviderReference,
                    $subscriptionId,
                    $now,
                ));
            }

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'subscription.created')
                : AuditEntry::forSystem('subscription.created', $command->clientId);
            $this->audit->record($entry->withTarget('subscription', $subscriptionId)->withChange(null, SubscriptionAuditSnapshot::of($subscription)));
        });

        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        return Result::ok(new CreateSubscriptionResult($subscriptionId, $subscription->status()->value));
    }
}
