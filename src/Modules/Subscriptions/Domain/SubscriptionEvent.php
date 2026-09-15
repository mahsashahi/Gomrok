<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain;

use DateTimeImmutable;

/**
 * One immutable event in a subscription's history (Phase 26) — mirrors
 * {@see \Gomrok\Modules\Payments\Domain\ProviderTransaction}'s shape.
 * `kind` is a generic, provider-agnostic label (`created` / `renewed` /
 * `charge_failed` / `cancelled` / `trial_ended` / `status_changed` / …), not
 * FK'd to any provider-specific type. Write-once — no update method on the
 * repository port.
 */
final readonly class SubscriptionEvent
{
    /**
     * @param array<array-key, mixed>|null $payload
     */
    public function __construct(
        public ?int $id,
        public int $subscriptionId,
        public string $kind,
        public ?string $providerStatusRaw,
        public ?array $payload,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $payload
     */
    public static function record(int $subscriptionId, string $kind, ?string $providerStatusRaw, ?array $payload, DateTimeImmutable $now): self
    {
        return new self(null, $subscriptionId, trim($kind), $providerStatusRaw, $payload, $now);
    }
}
