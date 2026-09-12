<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentAttemptRepository;

final class InMemoryPaymentAttemptRepository implements PaymentAttemptRepository
{
    /** @var array<int, PaymentAttempt> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(PaymentAttempt $attempt): void
    {
        if ($attempt->id() === null) {
            $attempt->assignId($this->nextId++);
        }
        $id = $attempt->id();
        \assert($id !== null);
        $this->byId[$id] = $attempt;
    }

    public function findById(int $id): ?PaymentAttempt
    {
        return $this->byId[$id] ?? null;
    }

    public function findLatestForPayment(int $paymentId): ?PaymentAttempt
    {
        $latest = null;
        foreach ($this->byId as $attempt) {
            if ($attempt->paymentId() !== $paymentId) {
                continue;
            }
            if ($latest === null || $attempt->attemptNumber() > $latest->attemptNumber()) {
                $latest = $attempt;
            }
        }

        return $latest;
    }

    public function countForPayment(int $paymentId): int
    {
        return \count($this->forPayment($paymentId));
    }

    public function forPayment(int $paymentId): array
    {
        $attempts = array_values(array_filter($this->byId, static fn (PaymentAttempt $a): bool => $a->paymentId() === $paymentId));
        usort($attempts, static fn (PaymentAttempt $a, PaymentAttempt $b): int => $a->attemptNumber() <=> $b->attemptNumber());

        return $attempts;
    }
}
