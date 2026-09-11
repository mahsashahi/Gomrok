<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionCommand;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionResult;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Reserves a voucher for a checkout attempt: looks the code up, calls Phase
 * 17's {@see ReserveVoucherRedemptionHandler} using the **same**
 * `attempt_reference` as the checkout attempt itself, freezes a
 * {@see VoucherDecisionSnapshot}, and advances the attempt to
 * `voucher_reserved`. Requires pricing to already be resolved (the reservation
 * needs the price to compute the discount against). Idempotent.
 */
final readonly class ReserveCheckoutVoucherHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private VoucherDecisionSnapshotRepository $voucherSnapshots,
        private PricingDecisionSnapshotRepository $pricingSnapshots,
        private VoucherRepository $vouchers,
        private VoucherRedemptionRepository $redemptions,
        private ReserveVoucherRedemptionHandler $reserveVoucher,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ReserveCheckoutVoucherCommand $command): Result
    {
        $attempt = $this->attempts->findById($command->checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$command->checkoutAttemptId} was not found for this client."));
        }

        $existing = $this->voucherSnapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($existing !== null) {
            $redemption = $this->redemptions->findById($existing->voucherRedemptionId);
            \assert($redemption !== null);

            return Result::ok(new ReserveCheckoutVoucherResult($existing->voucherRedemptionId, $redemption->payableMinor()));
        }

        $pricing = $this->pricingSnapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($pricing === null) {
            return Result::err(DomainError::validation('checkout_attempt.pricing_not_resolved', 'Resolve the checkout attempt price before reserving a voucher.'));
        }

        $voucher = $this->vouchers->findByCode($command->clientId, $command->voucherCode);
        if ($voucher === null) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher '{$command->voucherCode}' was not found for this client."));
        }
        $voucherId = $voucher->id();
        \assert($voucherId !== null);

        $reserveResult = $this->reserveVoucher->handle(new ReserveVoucherRedemptionCommand(
            clientId: $command->clientId,
            voucherId: $voucherId,
            attemptReference: $attempt->attemptReference(),
            currencyCode: $pricing->currencyCode,
            priceMinor: $pricing->amountMinor,
            country: $attempt->country(),
            packageId: $attempt->packageId(),
            paymentMethod: $attempt->paymentMethod()?->value,
            purchaseType: $attempt->purchaseType()?->value,
            subscriptionInterval: $attempt->subscriptionInterval()?->value,
            clientUserRef: $command->clientUserRef ?? $attempt->clientUserRef(),
            isFirstPurchase: $command->isFirstPurchase,
        ));
        if ($reserveResult->isErr()) {
            return $reserveResult;
        }
        $reservation = $reserveResult->value();
        \assert($reservation instanceof ReserveVoucherRedemptionResult);

        $redemption = $this->redemptions->findById($reservation->redemptionId);
        \assert($redemption !== null);

        $now = $this->clock->now();
        $snapshot = VoucherDecisionSnapshot::of($command->checkoutAttemptId, $voucher, $redemption, $now);

        $before = CheckoutAuditSnapshot::attempt($attempt);
        $error = $attempt->transitionTo(CheckoutAttemptStatus::VoucherReserved, $now);
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($snapshot, $attempt, $before, $command): void {
            $this->voucherSnapshots->save($snapshot);
            $this->attempts->save($attempt);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'checkout_attempt.voucher_reserved')
                : AuditEntry::forSystem('checkout_attempt.voucher_reserved', $command->clientId);

            $attemptId = $attempt->id();
            \assert($attemptId !== null);
            $this->audit->record($entry->withTarget('checkout_attempt', $attemptId)->withChange($before, CheckoutAuditSnapshot::attempt($attempt)));
        });

        return Result::ok(new ReserveCheckoutVoucherResult($reservation->redemptionId, $reservation->payableMinor));
    }
}
