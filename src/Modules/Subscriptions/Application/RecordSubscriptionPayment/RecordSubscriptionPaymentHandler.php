<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Payments\Application\PaymentAuditSnapshot;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionCommand;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\SubscriptionAuditSnapshot;
use Gomrok\Modules\Subscriptions\Domain\Events\SubscriptionStatusChanged;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEvent;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEventRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLink;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLinkRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Events\DomainEventDispatcher;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * The Phase 26 Q2 "second creation path" — records one recurring charge for
 * an existing subscription as a real {@see Payment} row with no checkout
 * attempt of its own, linked via `subscription_payment_links`. This is the
 * one place that creates a `Payment` outside `CreatePaymentHandler`.
 *
 * `country` isn't stored on `Subscription` (Phase 26 DB design: skipped for
 * now) — it's read from the subscription's origin
 * {@see \Gomrok\Modules\Checkout\Domain\CheckoutAttempt} instead, which
 * always exists (every subscription is created from exactly one).
 *
 * Idempotent by `providerPaymentReference`: a duplicate webhook redelivery
 * for the same charge is detected via the existing
 * `GatewayReferenceType::PaymentIntent` row before any `Payment` is created,
 * so a retried delivery can never create a second payment for one charge.
 * Once created, the payment is driven `created -> pending -> paid|failed`
 * through {@see RecordProviderTransactionHandler} — the same machinery a
 * checkout-originated payment uses — so provider-transaction logging and
 * payment-attempt bookkeeping aren't duplicated here.
 *
 * Reusable by design (CLAUDE.md, Phase 26 Q3): this is the shared processor
 * a Phase-29 real queue/webhook-driven renewal flow calls into unchanged;
 * only what triggers it changes.
 */
final readonly class RecordSubscriptionPaymentHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private CheckoutAttemptRepository $attempts,
        private PaymentRepository $payments,
        private SubscriptionPaymentLinkRepository $paymentLinks,
        private SubscriptionEventRepository $subscriptionEvents,
        private GatewayReferenceRepository $gatewayReferences,
        private RecordProviderTransactionHandler $recordTransaction,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private DomainEventDispatcher $events,
    ) {
    }

    public function handle(RecordSubscriptionPaymentCommand $command): Result
    {
        $subscription = $this->subscriptions->findById($command->subscriptionId);
        if ($subscription === null || $subscription->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('subscription.not_found', "Subscription {$command->subscriptionId} was not found for this client."));
        }

        $existingReference = $this->gatewayReferences->findByReference($subscription->providerAccountId(), GatewayReferenceType::PaymentIntent, $command->providerPaymentReference);
        if ($existingReference !== null && $existingReference->paymentId !== null) {
            $existingPayment = $this->payments->findById($existingReference->paymentId);
            \assert($existingPayment instanceof Payment);

            return Result::ok(new RecordSubscriptionPaymentResult($existingReference->paymentId, $existingPayment->status()->value, $subscription->status()->value, true));
        }

        $mappedStatus = PaymentStatus::tryFrom($command->mappedStatus);
        if ($mappedStatus === null) {
            return Result::err(DomainError::validation('subscription_payment.unknown_status', "Unknown payment status '{$command->mappedStatus}'.", ['status' => $command->mappedStatus]));
        }

        $attempt = $this->attempts->findById($subscription->checkoutAttemptId());
        if ($attempt === null) {
            return Result::err(DomainError::validation('subscription_payment.origin_attempt_missing', 'The subscription has no resolvable origin checkout attempt.'));
        }

        $now = $this->clock->now();
        $payment = Payment::create(
            $command->clientId,
            null,
            $subscription->clientUserRef(),
            $subscription->packageId(),
            $attempt->country(),
            $subscription->currencyCode(),
            $subscription->amountMinor(),
            PurchaseType::Subscription,
            $subscription->paymentMethod(),
            $subscription->interval(),
            $now,
        );

        $beforeSubscription = SubscriptionAuditSnapshot::of($subscription);

        $this->transactions->run(function () use ($payment, $subscription, $command, $now): void {
            $this->payments->save($payment);
            $paymentId = $payment->id();
            \assert($paymentId !== null);

            $subscriptionId = $subscription->id();
            \assert($subscriptionId !== null);
            $this->paymentLinks->save(SubscriptionPaymentLink::link($subscriptionId, $paymentId, $command->billingPeriodStart, $command->billingPeriodEnd, $now));

            $this->gatewayReferences->save(GatewayReference::forPayment(
                $command->clientId,
                $subscription->providerAccountId(),
                GatewayReferenceType::PaymentIntent,
                $command->providerPaymentReference,
                $paymentId,
                $now,
            ));

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'subscription.payment_created')
                : AuditEntry::forSystem('subscription.payment_created', $command->clientId);
            $this->audit->record($entry->withTarget('payment', $paymentId)->withChange(null, PaymentAuditSnapshot::payment($payment)));
        });

        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $pendingResult = $this->recordTransaction->handle(new RecordProviderTransactionCommand(
            clientId: $command->clientId,
            paymentId: $paymentId,
            providerAccountId: $subscription->providerAccountId(),
            kind: 'webhook',
            providerStatusRaw: $command->rawStatus,
            newStatus: PaymentStatus::Pending->value,
            actorId: $command->actorId,
        ));
        if ($pendingResult->isErr()) {
            return $pendingResult;
        }

        if ($mappedStatus !== PaymentStatus::Pending) {
            $finalResult = $this->recordTransaction->handle(new RecordProviderTransactionCommand(
                clientId: $command->clientId,
                paymentId: $paymentId,
                providerAccountId: $subscription->providerAccountId(),
                kind: 'webhook',
                providerStatusRaw: $command->rawStatus,
                newStatus: $mappedStatus->value,
                attemptOutcome: $mappedStatus === PaymentStatus::Paid ? 'succeeded' : 'failed',
                errorCode: $command->errorCode,
                errorMessage: $command->errorMessage,
                actorId: $command->actorId,
            ));
            if ($finalResult->isErr()) {
                return $finalResult;
            }
        }

        $previousSubscriptionStatus = $subscription->status();
        $transitionError = $this->applySubscriptionOutcome($subscription, $mappedStatus, $command, $now);
        if ($transitionError !== null) {
            return Result::err($transitionError);
        }
        $subscriptionStatusChanged = $subscription->status() !== $previousSubscriptionStatus;

        $this->transactions->run(function () use ($subscription, $beforeSubscription, $command, $now, $mappedStatus, $paymentId): void {
            $this->subscriptions->save($subscription);

            $subscriptionId = $subscription->id();
            \assert($subscriptionId !== null);

            $this->subscriptionEvents->save(SubscriptionEvent::record(
                $subscriptionId,
                $mappedStatus === PaymentStatus::Paid ? 'renewed' : 'charge_failed',
                $command->rawStatus,
                null,
                $now,
            ));

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'subscription.payment_recorded')
                : AuditEntry::forSystem('subscription.payment_recorded', $command->clientId);
            $this->audit->record(
                $entry->withTarget('subscription', $subscriptionId)
                    ->withChange($beforeSubscription, SubscriptionAuditSnapshot::of($subscription))
                    ->withContext(['payment_id' => $paymentId]),
            );
        });

        if ($subscriptionStatusChanged) {
            $subscriptionId = $subscription->id();
            \assert($subscriptionId !== null);
            $this->events->dispatch(new SubscriptionStatusChanged(
                $subscriptionId,
                $command->clientId,
                $previousSubscriptionStatus->value,
                $subscription->status()->value,
                $subscription->providerAccountId(),
                $now,
            ));
        }

        return Result::ok(new RecordSubscriptionPaymentResult($paymentId, $mappedStatus->value, $subscription->status()->value, false));
    }

    private function applySubscriptionOutcome(
        Subscription $subscription,
        PaymentStatus $mappedStatus,
        RecordSubscriptionPaymentCommand $command,
        DateTimeImmutable $now,
    ): ?DomainError {
        if ($command->billingPeriodStart !== null && $command->billingPeriodEnd !== null) {
            $subscription->recordPeriod($command->billingPeriodStart, $command->billingPeriodEnd, $now);
        }

        if ($mappedStatus === PaymentStatus::Paid) {
            return $subscription->transitionTo(SubscriptionStatus::Active, $now);
        }

        if ($mappedStatus === PaymentStatus::Failed) {
            return $subscription->transitionTo(SubscriptionStatus::PastDue, $now, $command->errorCode, $command->errorMessage);
        }

        return null;
    }
}
