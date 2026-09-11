<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\DomainError;

/**
 * The anchor for the whole pre-payment lifecycle (Phase 18 Q1): the internal
 * relational id every decision-snapshot table FKs to, while
 * {@see attemptReference()} is the external, caller-supplied idempotent key.
 * `id` is null until persisted.
 */
final class CheckoutAttempt
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly ?string $clientUserRef,
        private readonly string $attemptReference,
        private readonly int $packageId,
        private readonly string $country,
        private readonly string $currencyCode,
        private readonly ?PurchaseType $purchaseType,
        private readonly ?PaymentMethod $paymentMethod,
        private readonly ?SubscriptionInterval $subscriptionInterval,
        private CheckoutAttemptStatus $status,
        private ?string $errorCode,
        private ?string $errorMessage,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $abandonedAt,
        private ?DateTimeImmutable $expiredAt,
    ) {
    }

    public static function start(
        int $clientId,
        ?string $clientUserRef,
        string $attemptReference,
        int $packageId,
        string $country,
        string $currencyCode,
        ?PurchaseType $purchaseType,
        ?PaymentMethod $paymentMethod,
        ?SubscriptionInterval $subscriptionInterval,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            $clientId,
            $clientUserRef,
            trim($attemptReference),
            $packageId,
            strtoupper(trim($country)),
            strtoupper(trim($currencyCode)),
            $purchaseType,
            $paymentMethod,
            $subscriptionInterval,
            CheckoutAttemptStatus::Started,
            null,
            null,
            $now,
            null,
            null,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        ?string $clientUserRef,
        string $attemptReference,
        int $packageId,
        string $country,
        string $currencyCode,
        ?PurchaseType $purchaseType,
        ?PaymentMethod $paymentMethod,
        ?SubscriptionInterval $subscriptionInterval,
        CheckoutAttemptStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $abandonedAt,
        ?DateTimeImmutable $expiredAt,
    ): self {
        return new self(
            $id,
            $clientId,
            $clientUserRef,
            $attemptReference,
            $packageId,
            $country,
            $currencyCode,
            $purchaseType,
            $paymentMethod,
            $subscriptionInterval,
            $status,
            $errorCode,
            $errorMessage,
            $createdAt,
            $updatedAt,
            $abandonedAt,
            $expiredAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Phase 18 Q3's transition guard. `null` = applied (or a legal no-op);
     * a {@see DomainError} = rejected, no state changed.
     */
    public function transitionTo(CheckoutAttemptStatus $new, DateTimeImmutable $now, ?string $errorCode = null, ?string $errorMessage = null): ?DomainError
    {
        if ($this->status->isTerminal()) {
            return DomainError::conflict(
                'checkout_attempt.terminal',
                "This checkout attempt is already '{$this->status->value}' and cannot transition further.",
                ['status' => $this->status->value],
            );
        }

        if ($new === $this->status) {
            return null;
        }

        if ($new->isExit()) {
            // reachable from any non-terminal status, unconditionally.
        } elseif ($new === CheckoutAttemptStatus::ConvertedToPayment) {
            if ($this->status !== CheckoutAttemptStatus::Confirmed) {
                return DomainError::validation(
                    'checkout_attempt.not_confirmed',
                    'A checkout attempt must be confirmed before it can convert to a payment.',
                    ['status' => $this->status->value],
                );
            }
        } else {
            $currentRank = $this->status->rank();
            $newRank = $new->rank();
            if ($currentRank === null || $newRank === null || $newRank <= $currentRank) {
                return DomainError::validation(
                    'checkout_attempt.invalid_transition',
                    "Cannot move from '{$this->status->value}' to '{$new->value}'.",
                    ['from' => $this->status->value, 'to' => $new->value],
                );
            }
        }

        $this->status = $new;
        $this->updatedAt = $now;
        if ($new === CheckoutAttemptStatus::Failed) {
            $this->errorCode = $errorCode;
            $this->errorMessage = $errorMessage;
        }
        if ($new === CheckoutAttemptStatus::Abandoned) {
            $this->abandonedAt = $now;
        }
        if ($new === CheckoutAttemptStatus::Expired) {
            $this->expiredAt = $now;
        }

        return null;
    }

    /**
     * The commercial context this module can honestly state on its own —
     * Phase 20 merges this with each module's decision-snapshot row (found via
     * the same `checkout_attempt_id`) to build the final `payments` record.
     *
     * @return array<string, scalar|null>
     */
    public function commercialSnapshot(): array
    {
        return [
            'checkout_attempt_id' => $this->id,
            'client_id' => $this->clientId,
            'attempt_reference' => $this->attemptReference,
            'package_id' => $this->packageId,
            'country' => $this->country,
            'currency_code' => $this->currencyCode,
            'purchase_type' => $this->purchaseType?->value,
            'payment_method' => $this->paymentMethod?->value,
            'subscription_interval' => $this->subscriptionInterval?->value,
            'status' => $this->status->value,
        ];
    }

    public function id(): ?int
    {
        return $this->id;
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

    public function purchaseType(): ?PurchaseType
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

    public function status(): CheckoutAttemptStatus
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

    public function abandonedAt(): ?DateTimeImmutable
    {
        return $this->abandonedAt;
    }

    public function expiredAt(): ?DateTimeImmutable
    {
        return $this->expiredAt;
    }
}
