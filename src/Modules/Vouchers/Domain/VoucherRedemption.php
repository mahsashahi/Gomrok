<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainError;

/**
 * One reserve -> confirm/release attempt against a voucher (Phase 17), keyed by
 * a caller-supplied `attemptReference` (Phase 17 Q1 — unique per voucher; Phase
 * 20 passes the payment id once payments exist). `id` is null until persisted.
 * Price/discount amounts are a snapshot taken at reservation time.
 */
final class VoucherRedemption
{
    private function __construct(
        private ?int $id,
        private readonly int $voucherId,
        private readonly int $clientId,
        private readonly ?string $clientUserRef,
        private readonly string $attemptReference,
        private RedemptionStatus $status,
        private readonly string $currencyCode,
        private readonly int $priceMinor,
        private readonly int $nominalDiscountMinor,
        private readonly int $appliedDiscountMinor,
        private readonly int $payableMinor,
        private readonly DateTimeImmutable $reservedAt,
        private ?DateTimeImmutable $confirmedAt,
        private ?DateTimeImmutable $releasedAt,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function reserve(
        int $voucherId,
        int $clientId,
        ?string $clientUserRef,
        string $attemptReference,
        string $currencyCode,
        int $priceMinor,
        int $nominalDiscountMinor,
        int $appliedDiscountMinor,
        int $payableMinor,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $voucherId,
            $clientId,
            $clientUserRef,
            $attemptReference,
            RedemptionStatus::Reserved,
            strtoupper($currencyCode),
            $priceMinor,
            $nominalDiscountMinor,
            $appliedDiscountMinor,
            $payableMinor,
            $now,
            null,
            null,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $voucherId,
        int $clientId,
        ?string $clientUserRef,
        string $attemptReference,
        RedemptionStatus $status,
        string $currencyCode,
        int $priceMinor,
        int $nominalDiscountMinor,
        int $appliedDiscountMinor,
        int $payableMinor,
        DateTimeImmutable $reservedAt,
        ?DateTimeImmutable $confirmedAt,
        ?DateTimeImmutable $releasedAt,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $voucherId,
            $clientId,
            $clientUserRef,
            $attemptReference,
            $status,
            $currencyCode,
            $priceMinor,
            $nominalDiscountMinor,
            $appliedDiscountMinor,
            $payableMinor,
            $reservedAt,
            $confirmedAt,
            $releasedAt,
            $createdAt,
            $updatedAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Idempotent: confirming an already-confirmed redemption is a no-op.
     * A released redemption can never be confirmed.
     */
    public function confirm(DateTimeImmutable $now): ?DomainError
    {
        if ($this->status === RedemptionStatus::Confirmed) {
            return null;
        }
        if ($this->status === RedemptionStatus::Released) {
            return DomainError::conflict('voucher_redemption.already_released', 'This redemption was already released and cannot be confirmed.');
        }
        $this->status = RedemptionStatus::Confirmed;
        $this->confirmedAt = $now;
        $this->updatedAt = $now;

        return null;
    }

    /**
     * Idempotent: releasing an already-released redemption is a no-op.
     * A confirmed redemption can never be released (that needs a refund flow).
     */
    public function release(DateTimeImmutable $now): ?DomainError
    {
        if ($this->status === RedemptionStatus::Released) {
            return null;
        }
        if ($this->status === RedemptionStatus::Confirmed) {
            return DomainError::conflict('voucher_redemption.already_confirmed', 'This redemption was already confirmed and cannot be released.');
        }
        $this->status = RedemptionStatus::Released;
        $this->releasedAt = $now;
        $this->updatedAt = $now;

        return null;
    }

    public function isReserved(): bool
    {
        return $this->status === RedemptionStatus::Reserved;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function voucherId(): int
    {
        return $this->voucherId;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function clientUserRef(): ?string
    {
        return $this->clientUserRef;
    }

    public function attemptReference(): string
    {
        return $this->attemptReference;
    }

    public function status(): RedemptionStatus
    {
        return $this->status;
    }

    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    public function priceMinor(): int
    {
        return $this->priceMinor;
    }

    public function nominalDiscountMinor(): int
    {
        return $this->nominalDiscountMinor;
    }

    public function appliedDiscountMinor(): int
    {
        return $this->appliedDiscountMinor;
    }

    public function payableMinor(): int
    {
        return $this->payableMinor;
    }

    public function reservedAt(): DateTimeImmutable
    {
        return $this->reservedAt;
    }

    public function confirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function releasedAt(): ?DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
