<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateProviderCheckout;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand as ProviderCreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Calls the real provider adapter to create the hosted checkout session for a
 * `ProviderSelected` checkout attempt (Phase 24) — the caller Phase 21 Q5
 * deferred and Phase 21/22's own adapters were built ahead of. Builds the
 * return URL Gomrok's own return endpoint listens on (Q2), signs it with a
 * {@see \Gomrok\Modules\Checkout\Domain\CheckoutReturnToken} (Q4), records a
 * pre-payment {@see GatewayReference} (Q1 — no `payments` row exists yet),
 * and advances the attempt to `ProviderCheckoutCreated`.
 *
 * A `subscription` purchase type is rejected outright — that's
 * `POST /api/v1/subscriptions` (Phase 26), not this flow.
 */
final readonly class CreateProviderCheckoutHandler
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

    public function handle(CreateProviderCheckoutCommand $command): Result
    {
        $attempt = $this->attempts->findById($command->checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$command->checkoutAttemptId} was not found for this client."));
        }

        if ($attempt->status() !== CheckoutAttemptStatus::ProviderSelected) {
            return Result::err(DomainError::validation(
                'checkout_attempt.provider_not_selected',
                'A provider must be selected before creating the provider checkout.',
                ['status' => $attempt->status()->value],
            ));
        }

        $routing = $this->routingSnapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($routing === null) {
            return Result::err(DomainError::validation('checkout_attempt.provider_routing_missing', 'The checkout attempt has no resolved provider routing decision.'));
        }

        $purchaseType = PurchaseType::tryFrom($routing->purchaseType);
        if ($purchaseType === PurchaseType::Subscription) {
            return Result::err(DomainError::validation(
                'checkout_attempt.subscription_not_supported_here',
                'Subscriptions are created via POST /api/v1/subscriptions, not this endpoint.',
            ));
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

        $paymentMethod = $routing->paymentMethod !== null ? PaymentMethod::tryFrom($routing->paymentMethod) : null;

        try {
            $adapter = $this->adapterFactory->for($routing->providerAccountId);
            $providerResult = $adapter->createPayment(new ProviderCreatePaymentCommand(
                attemptReference: $attempt->attemptReference(),
                amountMinor: $amount->amountMinor,
                currencyCode: $amount->currencyCode,
                description: $description,
                successUrl: $base . '&outcome=success',
                cancelUrl: $base . '&outcome=cancel',
                customerEmail: $command->customerEmail,
                metadata: ['checkout_attempt_id' => (string) $attemptId],
                paymentMethod: $paymentMethod,
            ));
        } catch (UnsupportedProviderType $e) {
            return Result::err(DomainError::unsupported('checkout_attempt.provider_not_implemented', $e->getMessage()));
        } catch (ProviderAdapterException $e) {
            return Result::err(DomainError::upstreamFailure('checkout_attempt.provider_checkout_failed', $e->getMessage()));
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

        $this->transactions->run(function () use ($attempt, $reference, $before, $command, $attemptId): void {
            $this->attempts->save($attempt);
            $this->gatewayReferences->save($reference);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'checkout_attempt.provider_checkout_created')
                : AuditEntry::forSystem('checkout_attempt.provider_checkout_created', $command->clientId);
            $this->audit->record($entry->withTarget('checkout_attempt', $attemptId)->withChange($before, CheckoutAuditSnapshot::attempt($attempt)));
        });

        return Result::ok(new CreateProviderCheckoutResult($attemptId, $providerResult->redirectUrl, $providerResult->providerReference, $attempt->status()->value));
    }
}
