<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Domain\DomainError;

/**
 * The commercial record of one recurring purchase (Phase 26): created from
 * exactly one confirmed {@see \Gomrok\Modules\Checkout\Domain\CheckoutAttempt}
 * — the same origin a {@see \Gomrok\Modules\Payments\Domain\Payment} requires
 * (Phase 26 Q1, reusing the Checkout pipeline) — with a mandatory
 * `clientUserRef` (Q4: CLAUDE.md's Subscription Ownership Model requires "one
 * client user reference" unconditionally, unlike `Payment`'s optional one).
 * `id` is null until persisted.
 */
final class Subscription
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly string $clientUserRef,
        private readonly int $checkoutAttemptId,
        private readonly int $packageId,
        private readonly int $providerAccountId,
        private readonly string $currencyCode,
        private readonly int $amountMinor,
        private readonly ?PaymentMethod $paymentMethod,
        private readonly SubscriptionInterval $interval,
        private SubscriptionStatus $status,
        private readonly ?DateTimeImmutable $trialEndsAt,
        private ?DateTimeImmutable $currentPeriodStart,
        private ?DateTimeImmutable $currentPeriodEnd,
        private ?string $errorCode,
        private ?string $errorMessage,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        int $clientId,
        string $clientUserRef,
        int $checkoutAttemptId,
        int $packageId,
        int $providerAccountId,
        string $currencyCode,
        int $amountMinor,
        ?PaymentMethod $paymentMethod,
        SubscriptionInterval $interval,
        bool $hasTrial,
        ?int $trialDays,
        DateTimeImmutable $now,
    ): self {
        $trialEndsAt = $hasTrial && $trialDays !== null ? $now->modify("+{$trialDays} days") : null;
        $status = $trialEndsAt !== null ? SubscriptionStatus::Trialing : SubscriptionStatus::Active;

        return new self(
            null,
            $clientId,
            trim($clientUserRef),
            $checkoutAttemptId,
            $packageId,
            $providerAccountId,
            strtoupper(trim($currencyCode)),
            $amountMinor,
            $paymentMethod,
            $interval,
            $status,
            $trialEndsAt,
            null,
            null,
            null,
            null,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        string $clientUserRef,
        int $checkoutAttemptId,
        int $packageId,
        int $providerAccountId,
        string $currencyCode,
        int $amountMinor,
        ?PaymentMethod $paymentMethod,
        SubscriptionInterval $interval,
        SubscriptionStatus $status,
        ?DateTimeImmutable $trialEndsAt,
        ?DateTimeImmutable $currentPeriodStart,
        ?DateTimeImmutable $currentPeriodEnd,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $clientUserRef,
            $checkoutAttemptId,
            $packageId,
            $providerAccountId,
            $currencyCode,
            $amountMinor,
            $paymentMethod,
            $interval,
            $status,
            $trialEndsAt,
            $currentPeriodStart,
            $currentPeriodEnd,
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
     * Same guard shape as {@see \Gomrok\Modules\Payments\Domain\Payment::transitionTo()}:
     * terminal rejects everything (including a repeat of itself); same-status
     * is an idempotent no-op; anything not in {@see SubscriptionStatus::allowedNextStatuses()}
     * is rejected.
     */
    public function transitionTo(SubscriptionStatus $new, DateTimeImmutable $now, ?string $errorCode = null, ?string $errorMessage = null): ?DomainError
    {
        if ($this->status->isTerminal()) {
            return DomainError::conflict(
                'subscription.terminal',
                "This subscription is already '{$this->status->value}' and cannot transition further.",
                ['status' => $this->status->value],
            );
        }

        if ($new === $this->status) {
            return null;
        }

        if (!\in_array($new, $this->status->allowedNextStatuses(), true)) {
            return DomainError::validation(
                'subscription.invalid_transition',
                "Cannot move from '{$this->status->value}' to '{$new->value}'.",
                ['from' => $this->status->value, 'to' => $new->value],
            );
        }

        $this->status = $new;
        $this->updatedAt = $now;
        if ($new === SubscriptionStatus::PastDue) {
            $this->errorCode = $errorCode;
            $this->errorMessage = $errorMessage;
        } else {
            $this->errorCode = null;
            $this->errorMessage = null;
        }

        return null;
    }

    public function recordPeriod(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): void
    {
        $this->currentPeriodStart = $start;
        $this->currentPeriodEnd = $end;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function clientUserRef(): string
    {
        return $this->clientUserRef;
    }

    public function checkoutAttemptId(): int
    {
        return $this->checkoutAttemptId;
    }

    public function packageId(): int
    {
        return $this->packageId;
    }

    public function providerAccountId(): int
    {
        return $this->providerAccountId;
    }

    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function interval(): SubscriptionInterval
    {
        return $this->interval;
    }

    public function status(): SubscriptionStatus
    {
        return $this->status;
    }

    public function trialEndsAt(): ?DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function currentPeriodStart(): ?DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function currentPeriodEnd(): ?DateTimeImmutable
    {
        return $this->currentPeriodEnd;
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
