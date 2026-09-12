<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Payments\Domain\ProviderTransaction;
use Gomrok\Modules\Payments\Domain\ProviderTransactionRepository;

final class InMemoryProviderTransactionRepository implements ProviderTransactionRepository
{
    /** @var array<int, ProviderTransaction> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(ProviderTransaction $transaction): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = new ProviderTransaction(
            $id,
            $transaction->paymentAttemptId,
            $transaction->kind,
            $transaction->requestPayload,
            $transaction->responsePayload,
            $transaction->providerStatusRaw,
            $transaction->createdAt,
        );

        return $id;
    }

    public function forAttempt(int $paymentAttemptId): array
    {
        return array_values(array_filter($this->byId, static fn (ProviderTransaction $t): bool => $t->paymentAttemptId === $paymentAttemptId));
    }
}
