<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use DateTimeImmutable;

/**
 * A frozen copy of a {@see ResolvedPrice} decision (Phase 18 Q4), keyed to one
 * `checkout_attempts` row (`UNIQUE (checkout_attempt_id)` — exactly one price
 * decision per attempt). Write-once: the repository port has no update method.
 */
final readonly class PricingDecisionSnapshot
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public ?int $id,
        public int $checkoutAttemptId,
        public int $clientId,
        public int $packageId,
        public string $currencyCode,
        public int $amountMinor,
        public string $source,
        public array $payload,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function of(int $checkoutAttemptId, int $clientId, ResolvedPrice $price, DateTimeImmutable $now): self
    {
        return new self(
            null,
            $checkoutAttemptId,
            $clientId,
            $price->packageId,
            $price->currencyCode,
            $price->amountMinor,
            $price->source->value,
            $price->toArray(),
            $now,
        );
    }
}
