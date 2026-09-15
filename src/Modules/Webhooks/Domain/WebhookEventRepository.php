<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Domain;

/**
 * Persistence port for {@see WebhookEvent}.
 */
interface WebhookEventRepository
{
    public function save(WebhookEvent $event): void;

    public function findById(int $id): ?WebhookEvent;

    /**
     * The dedup lookup (Phase 25 Q2): `(providerAccountId, eventId, rawStatus)`.
     */
    public function findByDedupKey(int $providerAccountId, string $eventId, string $rawStatus): ?WebhookEvent;

    /**
     * Events the cron retry job should attempt: `received` / `retry_pending`,
     * plus any `processing` row stuck past `$staleAfterMinutes` (a crash
     * recovery net — an inline attempt that died mid-flight would otherwise
     * never be picked up again).
     *
     * @return list<WebhookEvent>
     */
    public function findRetryable(int $limit, int $staleAfterMinutes = 10): array;
}
