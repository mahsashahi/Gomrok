<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

use DateTimeImmutable;

/**
 * A frozen record of *which* voucher applied to a checkout attempt
 * (Phase 18 Q4) — thin by design: the immutable amounts already live on
 * {@see VoucherRedemption} (Phase 17 never mutates them after creation), so
 * this only denormalizes the voucher's identity (code, name) at decision time
 * in case a later `UpdateVoucher` renames it. `UNIQUE (checkout_attempt_id)` —
 * at most one voucher per checkout attempt. Write-once: the repository port
 * has no update method.
 */
final readonly class VoucherDecisionSnapshot
{
    public function __construct(
        public ?int $id,
        public int $checkoutAttemptId,
        public int $clientId,
        public int $voucherId,
        public int $voucherRedemptionId,
        public string $voucherCode,
        public string $voucherName,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function of(int $checkoutAttemptId, Voucher $voucher, VoucherRedemption $redemption, DateTimeImmutable $now): self
    {
        $voucherId = $voucher->id();
        $redemptionId = $redemption->id();
        \assert($voucherId !== null && $redemptionId !== null);

        return new self(
            null,
            $checkoutAttemptId,
            $voucher->clientId(),
            $voucherId,
            $redemptionId,
            $voucher->code(),
            $voucher->name(),
            $now,
        );
    }
}
