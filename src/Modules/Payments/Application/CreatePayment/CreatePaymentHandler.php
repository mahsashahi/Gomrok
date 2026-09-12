<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CreatePayment;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Payments\Application\PaymentAuditSnapshot;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Creates the final commercial record from exactly one confirmed checkout
 * attempt (Phase 20 Q1): requires `status === Confirmed`, copies
 * `CheckoutAttempt::commercialSnapshot()` plus the payable amount already
 * frozen by Pricing/Vouchers (the voucher's `payableMinor()` when one was
 * reserved, else the pricing snapshot's `amountMinor`), and transitions the
 * attempt to `converted_to_payment`. Idempotent: a repeat call for an attempt
 * that already has a payment returns it unchanged.
 */
final readonly class CreatePaymentHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private PaymentRepository $payments,
        private PricingDecisionSnapshotRepository $pricingSnapshots,
        private VoucherDecisionSnapshotRepository $voucherSnapshots,
        private VoucherRedemptionRepository $redemptions,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreatePaymentCommand $command): Result
    {
        $attempt = $this->attempts->findById($command->checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$command->checkoutAttemptId} was not found for this client."));
        }

        $existing = $this->payments->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($existing !== null) {
            return Result::ok(new CreatePaymentResult($this->requireId($existing), $existing->status()->value, $existing->amountMinor(), $existing->currencyCode()));
        }

        if ($attempt->status() !== CheckoutAttemptStatus::Confirmed) {
            return Result::err(DomainError::validation(
                'payment.checkout_attempt_not_confirmed',
                'The checkout attempt must be confirmed before a payment can be created.',
                ['status' => $attempt->status()->value],
            ));
        }

        $pricing = $this->pricingSnapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($pricing === null) {
            return Result::err(DomainError::validation('payment.pricing_not_resolved', 'The checkout attempt has no resolved pricing decision.'));
        }

        $amountMinor = $pricing->amountMinor;

        $voucherSnapshot = $this->voucherSnapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($voucherSnapshot !== null) {
            $redemption = $this->redemptions->findById($voucherSnapshot->voucherRedemptionId);
            \assert($redemption instanceof VoucherRedemption);
            $amountMinor = $redemption->payableMinor();
        }

        $purchaseType = $attempt->purchaseType();
        if ($purchaseType === null) {
            return Result::err(DomainError::validation('payment.purchase_type_missing', 'The checkout attempt has no purchase type set.'));
        }

        $now = $this->clock->now();
        $payment = Payment::create(
            $command->clientId,
            $command->checkoutAttemptId,
            $attempt->clientUserRef(),
            $attempt->packageId(),
            $attempt->country(),
            $pricing->currencyCode,
            $amountMinor,
            $purchaseType,
            $attempt->paymentMethod(),
            $attempt->subscriptionInterval(),
            $now,
        );

        $beforeAttempt = CheckoutAuditSnapshot::attempt($attempt);
        $error = $attempt->transitionTo(CheckoutAttemptStatus::ConvertedToPayment, $now);
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($payment, $attempt, $beforeAttempt, $command): void {
            $this->payments->save($payment);
            $this->attempts->save($attempt);

            $paymentId = $this->requireId($payment);
            $attemptId = $attempt->id();
            \assert($attemptId !== null);

            $paymentEntry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'payment.created')
                : AuditEntry::forSystem('payment.created', $command->clientId);
            $this->audit->record($paymentEntry->withTarget('payment', $paymentId)->withChange(null, PaymentAuditSnapshot::payment($payment)));

            $attemptEntry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'checkout_attempt.converted_to_payment')
                : AuditEntry::forSystem('checkout_attempt.converted_to_payment', $command->clientId);
            $this->audit->record(
                $attemptEntry->withTarget('checkout_attempt', $attemptId)
                    ->withChange($beforeAttempt, CheckoutAuditSnapshot::attempt($attempt))
                    ->withContext(['payment_id' => $paymentId]),
            );
        });

        return Result::ok(new CreatePaymentResult($this->requireId($payment), $payment->status()->value, $payment->amountMinor(), $payment->currencyCode()));
    }

    private function requireId(Payment $payment): int
    {
        $id = $payment->id();
        \assert($id !== null);

        return $id;
    }
}
