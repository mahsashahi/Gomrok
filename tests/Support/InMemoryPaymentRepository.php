<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentRepository;

final class InMemoryPaymentRepository implements PaymentRepository
{
    /** @var array<int, Payment> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(Payment $payment): void
    {
        if ($payment->id() === null) {
            $payment->assignId($this->nextId++);
        }
        $id = $payment->id();
        \assert($id !== null);
        $this->byId[$id] = $payment;
    }

    public function findById(int $id): ?Payment
    {
        return $this->byId[$id] ?? null;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?Payment
    {
        foreach ($this->byId as $payment) {
            if ($payment->checkoutAttemptId() === $checkoutAttemptId) {
                return $payment;
            }
        }

        return null;
    }

    public function forClient(int $clientId): array
    {
        return array_values(array_filter($this->byId, static fn (Payment $p): bool => $p->clientId() === $clientId));
    }
}
