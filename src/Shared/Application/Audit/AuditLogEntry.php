<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Audit;

/**
 * Read-only view of one `audit_logs` row (Phase 27's Audit Logs admin screen).
 * `before` / `after` / `context` are already secret-redacted at write time by
 * {@see \Gomrok\Shared\Infrastructure\Persistence\PdoAuditLogWriter} — this
 * DTO just decodes the stored JSON back into arrays, it does not redact again.
 */
final readonly class AuditLogEntry
{
    /**
     * @param array<array-key, mixed>|null $before  decoded JSON — always an object in
     *                                               practice (string keys), but PHP's
     *                                               `json_decode` gives no static guarantee
     * @param array<array-key, mixed>|null $after
     * @param array<array-key, mixed>|null $context
     */
    public function __construct(
        public int $id,
        public string $actorType,
        public ?int $actorId,
        public ?int $clientId,
        public string $action,
        public ?string $targetType,
        public ?int $targetId,
        public ?array $before,
        public ?array $after,
        public ?array $context,
        public ?string $correlationId,
        public ?string $ip,
        public ?string $userAgent,
        public string $createdAt,
    ) {
    }
}
