<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\DomainError;

/**
 * The commercial record of one purchase (Phase 20 Q1): created from exactly
 * one confirmed {@see \Gomrok\Modules\Checkout\Domain\CheckoutAttempt}, whose
 * `commercialSnapshot()` supplies every field below except `amountMinor` —
 * that comes from the checkout attempt's pricing/voucher decision snapshots,
 * frozen here and never re-derived. `id` is null until persisted.
 */
final class Payment
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly int $checkoutAttemptId,
        private readonly ?string $clientUserRef,
        private readonly int $packageId,
        private readonly string $country,
        private readonly string $currencyCode,
        private readonly int $amountMinor,
        private readonly PurchaseType $purchaseType,
        private readonly ?PaymentMethod $paymentMethod,
        private readonly ?SubscriptionInterval $subscriptionInterval,
        private PaymentStatus $status,
        private ?string $errorCode,
        private ?string $errorMessage,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        int $clientId,
        int $checkoutAttemptId,
        ?string $clientUserRef,
        int $packageId,
        string $country,
        string $currencyCode,
        int $amountMinor,
        PurchaseType $purchaseType,
        ?PaymentMethod $paymentMethod,
        ?SubscriptionInterval $subscriptionInterval,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $checkoutAttemptId,
            $clientUserRef,
            $packageId,
            strtoupper(trim($country)),
            strtoupper(trim($currencyCode)),
            $amountMinor,
            $purchaseType,
            $paymentMethod,
            $subscriptionInterval,
            PaymentStatus::Created,
            null,
            null,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        int $checkoutAttemptId,
        ?string $clientUserRef,
        int $packageId,
        string $country,
        string $currencyCode,
        int $amountMinor,
        PurchaseType $purchaseType,
        ?PaymentMethod $paymentMethod,
        ?SubscriptionInterval $subscriptionInterval,
        PaymentStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $checkoutAttemptId,
            $clientUserRef,
            $packageId,
            $country,
            $currencyCode,
            $amountMinor,
            $purchaseType,
            $paymentMethod,
            $subscriptionInterval,
            $status,
            $errorCode,
            $errorMessage,
            $createdAt,
            $updatedAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Phase 20 Q2's transition guard: same-status is an idempotent no-op;
     * anything not in {@see PaymentStatus::allowedNextStatuses()} is rejected;
     * a terminal status accepts nothing further.
     */
    public function transitionTo(PaymentStatus $new, DateTimeImmutable $now, ?string $errorCode = null, ?string $errorMessage = null): ?DomainError
    {
        if ($this->status->isTerminal()) {
            return DomainError::conflict(
                'payment.terminal',
                "This payment is already '{$this->status->value}' and cannot transition further.",
                ['status' => $this->status->value],
            );
        }

        if ($new === $this->status) {
            return null;
        }

        if (!\in_array($new, $this->status->allowedNextStatuses(), true)) {
            return DomainError::validation(
                'payment.invalid_transition',
                "Cannot move from '{$this->status->value}' to '{$new->value}'.",
                ['from' => $this->status->value, 'to' => $new->value],
            );
        }

        $this->status = $new;
        $this->updatedAt = $now;
        if ($new === PaymentStatus::Failed) {
            $this->errorCode = $errorCode;
            $this->errorMessage = $errorMessage;
        }

        return null;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function checkoutAttemptId(): int
    {
        return $this->checkoutAttemptId;
    }

    public function clientUserRef(): ?string
    {
        return $this->clientUserRef;
    }

    public function packageId(): int
    {
        return $this->packageId;
    }

    public function country(): string
    {
        return $this->country;
    }

    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function purchaseType(): PurchaseType
    {
        return $this->purchaseType;
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function subscriptionInterval(): ?SubscriptionInterval
    {
        return $this->subscriptionInterval;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
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
