<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

use DateTimeImmutable;

/**
 * A frozen copy of a {@see RoutingDecision} (Phase 18 Q4), keyed to one
 * `checkout_attempts` row (`UNIQUE (checkout_attempt_id)`). Write-once: the
 * repository port has no update method.
 */
final readonly class ProviderRoutingDecisionSnapshot
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public ?int $id,
        public int $checkoutAttemptId,
        public int $clientId,
        public int $providerAccountId,
        public ?string $paymentMethod,
        public string $purchaseType,
        public array $payload,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function of(int $checkoutAttemptId, RoutingDecision $decision, DateTimeImmutable $now): self
    {
        return new self(
            null,
            $checkoutAttemptId,
            $decision->clientId,
            $decision->chosen()->accountId,
            $decision->paymentMethod,
            $decision->purchaseType,
            $decision->toArray(),
            $now,
        );
    }
}
