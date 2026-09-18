<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateProviderSubscription;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Providers\Application\Adapter\CreateSubscriptionCommand as ProviderCreateSubscriptionCommand;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\SupportsSubscriptions;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * The subscription counterpart to `CreateProviderCheckoutHandler` (Phase 26
 * Q1 — reuses the same Checkout pipeline). Calls the real provider adapter's
 * `createSubscription()` for a `ProviderSelected` checkout attempt whose
 * `purchaseType` is `subscription`; every other purchase type is rejected
 * outright — that's `POST /api/v1/payments`, not this flow. Requires the
 * resolved adapter to implement {@see SupportsSubscriptions} — a provider
 * type may declare `PurchaseType::Subscription` support without its adapter
 * actually implementing the interface yet (PayPal, Phase 22 Q8), so this is
 * a real, reachable rejection, not just defensive dead code.
 */
final readonly class CreateProviderSubscriptionHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private ProviderRoutingDecisionSnapshotRepository $routingSnapshots,
        private ResolveCheckoutPayableAmount $payableAmount,
        private PackageDirectory $packages,
        private ProviderAdapterFactory $adapterFactory,
        private GatewayReferenceRepository $gatewayReferences,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private string $appBaseUrl,
        private string $returnTokenSecret,
    ) {
    }

    public function handle(CreateProviderSubscriptionCommand $command): Result
    {
        $attempt = $this->attempts->findById($command->checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$command->checkoutAttemptId} was not found for this client."));
        }

        if ($attempt->status() !== CheckoutAttemptStatus::ProviderSelected) {
            return Result::err(DomainError::validation(
                'checkout_attempt.provider_not_selected',
                'A provider must be selected before creating the provider subscription.',
                ['status' => $attempt->status()->value],
            ));
        }

        $routing = $this->routingSnapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($routing === null) {
            return Result::err(DomainError::validation('checkout_attempt.provider_routing_missing', 'The checkout attempt has no resolved provider routing decision.'));
        }

        $purchaseType = PurchaseType::tryFrom($routing->purchaseType);
        if ($purchaseType !== PurchaseType::Subscription) {
            return Result::err(DomainError::validation(
                'checkout_attempt.not_a_subscription',
                'This checkout attempt is not a subscription purchase; use POST /api/v1/payments instead.',
            ));
        }

        $interval = $attempt->subscriptionInterval();
        if ($interval === null) {
            return Result::err(DomainError::validation('checkout_attempt.subscription_interval_missing', 'A subscription checkout attempt must have a subscription interval.'));
        }

        $amount = $this->payableAmount->forCheckoutAttempt($command->checkoutAttemptId);
        if ($amount === null) {
            return Result::err(DomainError::validation('checkout_attempt.pricing_not_resolved', 'The checkout attempt has no resolved pricing decision.'));
        }

        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $token = $attempt->issueReturnToken($this->returnTokenSecret);
        $base = rtrim($this->appBaseUrl, '/') . '/payments/return?return_token=' . rawurlencode((string) $token);

        $package = $this->packages->findById($attempt->packageId());
        $description = $package !== null ? $package->name : "Package #{$attempt->packageId()}";

        try {
            $adapter = $this->adapterFactory->for($routing->providerAccountId);
        } catch (UnsupportedProviderType $e) {
            return Result::err(DomainError::unsupported('checkout_attempt.provider_not_implemented', $e->getMessage()));
        }

        if (!$adapter instanceof SupportsSubscriptions) {
            return Result::err(DomainError::unsupported(
                'checkout_attempt.subscriptions_not_supported',
                'The selected provider does not support subscriptions.',
            ));
        }

        try {
            $providerResult = $adapter->createSubscription(new ProviderCreateSubscriptionCommand(
                attemptReference: $attempt->attemptReference(),
                amountMinor: $amount->amountMinor,
                currencyCode: $amount->currencyCode,
                description: $description,
                interval: $interval,
                successUrl: $base . '&outcome=success',
                cancelUrl: $base . '&outcome=cancel',
                customerEmail: $command->customerEmail,
                metadata: ['checkout_attempt_id' => (string) $attemptId],
            ));
        } catch (ProviderAdapterException $e) {
            return Result::err(DomainError::upstreamFailure('checkout_attempt.provider_subscription_failed', $e->getMessage()));
        }

        $before = CheckoutAuditSnapshot::attempt($attempt);
        $now = $this->clock->now();
        $error = $attempt->transitionTo(CheckoutAttemptStatus::ProviderCheckoutCreated, $now);
        if ($error !== null) {
            return Result::err($error);
        }

        $reference = GatewayReference::forCheckoutAttempt(
            $command->clientId,
            $routing->providerAccountId,
            GatewayReferenceType::CheckoutSession,
            $providerResult->providerReference,
            $attemptId,
            $now,
        );

        // Mollie only (Phase 29 Q2) — the provider's own customer id,
        // recorded so the deferred-activation job can find it once the
        // first-payment mandate is confirmed.
        $customerReference = $providerResult->customerId !== null
            ? GatewayReference::forCheckoutAttempt(
                $command->clientId,
                $routing->providerAccountId,
                GatewayReferenceType::Customer,
                $providerResult->customerId,
                $attemptId,
                $now,
            )
            : null;

        $this->transactions->run(function () use ($attempt, $reference, $customerReference, $before, $command, $attemptId): void {
            $this->attempts->save($attempt);
            $this->gatewayReferences->save($reference);
            if ($customerReference !== null) {
                $this->gatewayReferences->save($customerReference);
            }

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'checkout_attempt.provider_subscription_created')
                : AuditEntry::forSystem('checkout_attempt.provider_subscription_created', $command->clientId);
            $this->audit->record($entry->withTarget('checkout_attempt', $attemptId)->withChange($before, CheckoutAuditSnapshot::attempt($attempt)));
        });

        return Result::ok(new CreateProviderSubscriptionResult($attemptId, $providerResult->redirectUrl, $providerResult->providerReference, $attempt->status()->value));
    }
}
