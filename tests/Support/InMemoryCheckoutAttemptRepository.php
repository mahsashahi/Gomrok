<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;

final class InMemoryCheckoutAttemptRepository implements CheckoutAttemptRepository
{
    /** @var array<int, CheckoutAttempt> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(CheckoutAttempt $attempt): void
    {
        if ($attempt->id() === null) {
            $attempt->assignId($this->nextId++);
        }
        $id = $attempt->id();
        \assert($id !== null);
        $this->byId[$id] = $attempt;
    }

    public function findById(int $id): ?CheckoutAttempt
    {
        return $this->byId[$id] ?? null;
    }

    public function findByAttemptReference(int $clientId, string $attemptReference): ?CheckoutAttempt
    {
        foreach ($this->byId as $attempt) {
            if ($attempt->clientId() === $clientId && $attempt->attemptReference() === $attemptReference) {
                return $attempt;
            }
        }

        return null;
    }

    public function forClient(int $clientId): array
    {
        return array_values(array_filter($this->byId, static fn (CheckoutAttempt $a): bool => $a->clientId() === $clientId));
    }
}
