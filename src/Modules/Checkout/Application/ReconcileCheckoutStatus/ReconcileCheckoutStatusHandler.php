<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Payments\Application\ChangePaymentStatus\ChangePaymentStatusHandler;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentCommand;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentHandler;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentResult;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Re-checks a checkout attempt's real status with the provider and advances
 * it accordingly (Phase 24) — the shared core both
 * `ConfirmCheckoutReturnHandler` (the public return endpoint, Q2) and
 * `GET /api/v1/payments/{id}/status` (an authenticated, on-demand poll —
 * CLAUDE.md: "verify payment status with the gateway API when needed") drive
 * through. Idempotent: an already-terminal attempt is reported as-is without
 * a second provider call.
 */
final readonly class ReconcileCheckoutStatusHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private ProviderRoutingDecisionSnapshotRepository $routingSnapshots,
        private GatewayReferenceRepository $gatewayReferences,
        private ProviderAdapterFactory $adapterFactory,
        private CreatePaymentHandler $createPayment,
        private ChangePaymentStatusHandler $changePaymentStatus,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(int $checkoutAttemptId): Result
    {
        $attempt = $this->attempts->findById($checkoutAttemptId);
        if ($attempt === null) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$checkoutAttemptId} was not found."));
        }

        if ($attempt->status()->isTerminal()) {
            return Result::ok(new ReconcileCheckoutStatusResult(
                $checkoutAttemptId,
                $attempt->clientId(),
                $attempt->status()->value,
                $attempt->status() === CheckoutAttemptStatus::ConvertedToPayment,
            ));
        }

        $createdRank = CheckoutAttemptStatus::ProviderCheckoutCreated->rank();
        $currentRank = $attempt->status()->rank();
        if ($currentRank === null || $createdRank === null || $currentRank < $createdRank) {
            return Result::err(DomainError::validation('checkout_attempt.no_provider_checkout_yet', 'No provider checkout has been created for this attempt yet.'));
        }

        $routing = $this->routingSnapshots->findByCheckoutAttemptId($checkoutAttemptId);
        if ($routing === null) {
            return Result::err(DomainError::validation('checkout_attempt.provider_routing_missing', 'The checkout attempt has no resolved provider routing decision.'));
        }

        $references = $this->gatewayReferences->forCheckoutAttempt($checkoutAttemptId);
        $reference = $references[\count($references) - 1] ?? null;
        if ($reference === null) {
            return Result::err(DomainError::validation('checkout_attempt.no_provider_reference', 'The checkout attempt has no recorded provider checkout session.'));
        }

        try {
            $status = $this->adapterFactory->for($routing->providerAccountId)->getPaymentStatus($reference->referenceValue);
        } catch (ProviderAdapterException $e) {
            return Result::err(DomainError::upstreamFailure('checkout_attempt.provider_status_check_failed', $e->getMessage()));
        }

        $now = $this->clock->now();
        $before = CheckoutAuditSnapshot::attempt($attempt);

        $attempt->transitionTo(CheckoutAttemptStatus::ReturnedFromProvider, $now);

        $paid = $status->mappedStatus === PaymentStatus::Paid;
        $exitStatus = match ($status->mappedStatus) {
            PaymentStatus::Canceled => CheckoutAttemptStatus::Canceled,
            PaymentStatus::Expired => CheckoutAttemptStatus::Expired,
            PaymentStatus::Failed => CheckoutAttemptStatus::Failed,
            default => null,
        };

        if ($paid) {
            $attempt->transitionTo(CheckoutAttemptStatus::Confirmed, $now);
        } elseif ($exitStatus !== null) {
            $attempt->transitionTo($exitStatus, $now, 'provider_payment_not_successful', "Provider reported status '{$status->rawStatus}'.");
        }

        $this->transactions->run(function () use ($attempt, $before, $checkoutAttemptId): void {
            $this->attempts->save($attempt);

            $entry = AuditEntry::forSystem('checkout_attempt.status_reconciled', $attempt->clientId())
                ->withTarget('checkout_attempt', $checkoutAttemptId)
                ->withChange($before, CheckoutAuditSnapshot::attempt($attempt));
            $this->audit->record($entry);
        });

        if ($attempt->status() === CheckoutAttemptStatus::Confirmed) {
            $paymentResult = $this->createPayment->handle(new CreatePaymentCommand($attempt->clientId(), $checkoutAttemptId));
            if ($paymentResult->isErr()) {
                // The attempt is genuinely Confirmed but converting it failed
                // for some other reason (a real bug, not a business rule) —
                // surface it rather than silently leaving the attempt
                // dangling at Confirmed with no Payment and no visible error.
                return $paymentResult;
            }

            $created = $paymentResult->value();
            \assert($created instanceof CreatePaymentResult);

            // The provider already confirmed this is paid (that's the only way
            // $attempt->status() reaches Confirmed above) — advance the new
            // Payment out of its initial `created` status to `paid` right away,
            // rather than leaving it stuck at `created` with no driver.
            // `created` can only reach `paid` via `pending` (PaymentStatus's
            // allowed-next-statuses graph), so this is two hops, not one.
            $pendingResult = $this->changePaymentStatus->handle($created->paymentId, $attempt->clientId(), PaymentStatus::Pending->value);
            if ($pendingResult->isErr()) {
                return $pendingResult;
            }
            $statusResult = $this->changePaymentStatus->handle($created->paymentId, $attempt->clientId(), PaymentStatus::Paid->value);
            if ($statusResult->isErr()) {
                return $statusResult;
            }

            // Persist the deeper provider reference (Stripe's PaymentIntent id,
            // PayPal's capture id via the same field) that a later
            // refund/capture/cancel needs (Phase 24 Q5) — computed by
            // getPaymentStatus() above but otherwise never stored anywhere.
            if ($status->paymentIntentReference !== null) {
                $this->gatewayReferences->save(GatewayReference::forPayment(
                    $attempt->clientId(),
                    $routing->providerAccountId,
                    GatewayReferenceType::PaymentIntent,
                    $status->paymentIntentReference,
                    $created->paymentId,
                    $now,
                ));
            }
        }

        return Result::ok(new ReconcileCheckoutStatusResult($checkoutAttemptId, $attempt->clientId(), $attempt->status()->value, $paid));
    }
}
