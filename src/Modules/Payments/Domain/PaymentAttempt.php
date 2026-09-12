<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Domain\DomainError;

/**
 * One distinct "try" against a provider for a {@see Payment} (Phase 20 Q3): a
 * declined card retried with a different method is a **new** attempt on the
 * same payment, `attempt_number` incrementing each time. `id` is null until
 * persisted.
 */
final class PaymentAttempt
{
    private function __construct(
        private ?int $id,
        private readonly int $paymentId,
        private readonly int $providerAccountId,
        private readonly int $attemptNumber,
        private PaymentAttemptStatus $status,
        private readonly ?PaymentMethod $paymentMethod,
        private ?string $errorCode,
        private ?string $errorMessage,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function start(int $paymentId, int $providerAccountId, int $attemptNumber, ?PaymentMethod $paymentMethod, DateTimeImmutable $now): self
    {
        return new self(null, $paymentId, $providerAccountId, $attemptNumber, PaymentAttemptStatus::Started, $paymentMethod, null, null, $now, null);
    }

    public static function fromStorage(
        int $id,
        int $paymentId,
        int $providerAccountId,
        int $attemptNumber,
        PaymentAttemptStatus $status,
        ?PaymentMethod $paymentMethod,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $paymentId, $providerAccountId, $attemptNumber, $status, $paymentMethod, $errorCode, $errorMessage, $createdAt, $updatedAt);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function succeed(DateTimeImmutable $now): ?DomainError
    {
        return $this->complete(PaymentAttemptStatus::Succeeded, $now, null, null);
    }

    public function fail(DateTimeImmutable $now, ?string $errorCode, ?string $errorMessage): ?DomainError
    {
        return $this->complete(PaymentAttemptStatus::Failed, $now, $errorCode, $errorMessage);
    }

    private function complete(PaymentAttemptStatus $new, DateTimeImmutable $now, ?string $errorCode, ?string $errorMessage): ?DomainError
    {
        if ($this->status !== PaymentAttemptStatus::Started) {
            return DomainError::conflict(
                'payment_attempt.already_completed',
                "This attempt is already '{$this->status->value}' and cannot be completed again.",
                ['status' => $this->status->value],
            );
        }

        $this->status = $new;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = $now;

        return null;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function paymentId(): int
    {
        return $this->paymentId;
    }

    public function providerAccountId(): int
    {
        return $this->providerAccountId;
    }

    public function attemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function status(): PaymentAttemptStatus
    {
        return $this->status;
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
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
