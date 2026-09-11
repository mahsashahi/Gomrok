<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption;

use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Application\VoucherContext;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountResult;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Reserves a voucher's usage against a checkout attempt: re-runs the Phase 16
 * eligibility gate (now including the Phase 17 usage checks) and computes the
 * discount, all inside a transaction that holds a `FOR UPDATE` lock on the
 * `vouchers` row (Phase 17 Q3) — the same lock every other redemption write
 * for this voucher goes through, making the caps race-free.
 *
 * Idempotent: a repeat call with the same `(voucherId, attemptReference)`
 * returns the existing redemption's current state rather than re-validating.
 */
final readonly class ReserveVoucherRedemptionHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private VoucherRedemptionRepository $redemptions,
        private VoucherEligibilityEvaluator $evaluator,
        private VoucherDiscountCalculator $calculator,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ReserveVoucherRedemptionCommand $command): Result
    {
        if (trim($command->attemptReference) === '') {
            return Result::err(DomainError::validation('voucher_redemption.attempt_reference_required', 'An attempt reference is required.'));
        }

        $method = null;
        if ($command->paymentMethod !== null) {
            $method = PaymentMethod::tryFrom($command->paymentMethod);
            if ($method === null) {
                return Result::err(DomainError::validation('voucher_redemption.unknown_method', "Unknown payment method '{$command->paymentMethod}'.", ['method' => $command->paymentMethod]));
            }
        }

        $purchaseType = null;
        if ($command->purchaseType !== null) {
            $purchaseType = PurchaseType::tryFrom($command->purchaseType);
            if ($purchaseType === null) {
                return Result::err(DomainError::validation('voucher_redemption.unknown_purchase_type', "Unknown purchase type '{$command->purchaseType}'.", ['purchase_type' => $command->purchaseType]));
            }
        }

        $interval = null;
        if ($command->subscriptionInterval !== null) {
            $interval = SubscriptionInterval::tryFrom($command->subscriptionInterval);
            if ($interval === null) {
                return Result::err(DomainError::validation('voucher_redemption.unknown_interval', "Unknown subscription interval '{$command->subscriptionInterval}'.", ['interval' => $command->subscriptionInterval]));
            }
        }

        if ($command->priceMinor < 0) {
            return Result::err(DomainError::validation('voucher_redemption.negative_price', 'The price cannot be negative.'));
        }

        $now = $this->clock->now();

        return $this->transactions->run(function () use ($command, $method, $purchaseType, $interval, $now): Result {
            $voucher = $this->vouchers->findByIdForUpdate($command->voucherId);
            if ($voucher === null || $voucher->clientId() !== $command->clientId) {
                return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$command->voucherId} was not found for this client."));
            }

            $existing = $this->redemptions->findByAttemptReference($command->voucherId, $command->attemptReference);
            if ($existing !== null) {
                return Result::ok($this->toResult($existing));
            }

            $context = new VoucherContext(
                $command->clientId,
                $now,
                $command->country,
                $command->currencyCode,
                $command->packageId,
                $command->providerAccountId,
                $method,
                $purchaseType,
                $interval,
                $command->priceMinor,
                $command->currencyCode,
                $command->clientUserRef,
                $command->isFirstPurchase,
            );

            $eligibility = $this->evaluator->evaluate($voucher, $context);
            if (!$eligibility->eligible) {
                return Result::err(DomainError::unsupported(
                    'voucher.not_eligible',
                    'This voucher cannot be used for this checkout.',
                    ['reasons' => implode(',', $eligibility->reasons)],
                ));
            }

            $discountResult = $this->calculator->calculate($voucher, $command->currencyCode, $command->priceMinor);
            if ($discountResult->isErr()) {
                return $discountResult;
            }
            $discount = $discountResult->value();
            \assert($discount instanceof VoucherDiscountResult);

            $redemption = VoucherRedemption::reserve(
                $command->voucherId,
                $command->clientId,
                $command->clientUserRef,
                $command->attemptReference,
                $command->currencyCode,
                $discount->priceMinor,
                $discount->nominalDiscountMinor,
                $discount->appliedDiscountMinor,
                $discount->payableMinor,
                $now,
            );

            $this->redemptions->save($redemption);
            $redemptionId = $redemption->id();
            \assert($redemptionId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'voucher_redemption.reserved')
                : AuditEntry::forSystem('voucher_redemption.reserved', $command->clientId);
            $this->audit->record(
                $entry->withTarget('voucher_redemption', $redemptionId)
                    ->withChange(null, VoucherAuditSnapshot::redemption($redemption))
                    ->withContext(['voucher_id' => $command->voucherId, 'attempt_reference' => $command->attemptReference]),
            );

            return Result::ok($this->toResult($redemption));
        });
    }

    private function toResult(VoucherRedemption $redemption): ReserveVoucherRedemptionResult
    {
        $redemptionId = $redemption->id();
        \assert($redemptionId !== null);

        return new ReserveVoucherRedemptionResult(
            $redemptionId,
            $redemption->status()->value,
            $redemption->currencyCode(),
            $redemption->priceMinor(),
            $redemption->nominalDiscountMinor(),
            $redemption->appliedDiscountMinor(),
            $redemption->payableMinor(),
        );
    }
}
