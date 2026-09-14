<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Checkout\Application\CheckoutAttemptSummary;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;

/**
 * A {@see CheckoutAttemptDirectory} projected straight off an
 * {@see InMemoryCheckoutAttemptRepository} — so a test that seeds attempts
 * via the repository (the normal way, through the domain aggregate) sees the
 * same data through the read-side directory, without duplicating storage.
 */
final readonly class InMemoryCheckoutAttemptDirectory implements CheckoutAttemptDirectory
{
    public function __construct(private InMemoryCheckoutAttemptRepository $attempts)
    {
    }

    public function forClient(int $clientId): array
    {
        return array_map($this->toSummary(...), $this->attempts->forClient($clientId));
    }

    public function findByAttemptReference(int $clientId, string $attemptReference): ?CheckoutAttemptSummary
    {
        $attempt = $this->attempts->findByAttemptReference($clientId, $attemptReference);

        return $attempt !== null ? $this->toSummary($attempt) : null;
    }

    public function findById(int $id): ?CheckoutAttemptSummary
    {
        $attempt = $this->attempts->findById($id);

        return $attempt !== null ? $this->toSummary($attempt) : null;
    }

    private function toSummary(CheckoutAttempt $attempt): CheckoutAttemptSummary
    {
        return new CheckoutAttemptSummary(
            $attempt->id() ?? 0,
            $attempt->clientId(),
            $attempt->clientUserRef(),
            $attempt->attemptReference(),
            $attempt->packageId(),
            $attempt->country(),
            $attempt->currencyCode(),
            $attempt->purchaseType()?->value,
            $attempt->paymentMethod()?->value,
            $attempt->subscriptionInterval()?->value,
            $attempt->status()->value,
            $attempt->errorCode(),
            $attempt->errorMessage(),
            $attempt->createdAt()->format('Y-m-d H:i:s'),
            $attempt->updatedAt()?->format('Y-m-d H:i:s'),
        );
    }
}
