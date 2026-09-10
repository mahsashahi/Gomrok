<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Audit;

/**
 * One row for `audit_logs`. Build it with a `for*()` factory, then layer on
 * `with*()` copies:
 *
 * ```php
 * AuditEntry::forAdminUser($adminUserId, $clientId, 'provider_config.updated')
 *     ->withTarget('provider_config', $configId)
 *     ->withChange($before, $after)
 *     ->withRequest($correlationId, $ip, $userAgent);
 * ```
 *
 * `before` / `after` are the full target rows; the writer redacts secret-bearing
 * keys before persisting.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public AuditActor $actorType,
        public ?int $actorId,
        public ?int $clientId,
        public string $action,
        public ?string $targetType = null,
        public ?int $targetId = null,
        public ?array $before = null,
        public ?array $after = null,
        public ?array $context = null,
        public ?string $correlationId = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {
    }

    public static function forAdminUser(int $adminUserId, ?int $clientId, string $action): self
    {
        return new self(AuditActor::AdminUser, $adminUserId, $clientId, $action);
    }

    public static function forClient(int $clientId, string $action): self
    {
        return new self(AuditActor::Client, $clientId, $clientId, $action);
    }

    public static function forSystem(string $action, ?int $clientId = null): self
    {
        return new self(AuditActor::System, null, $clientId, $action);
    }

    public function withTarget(string $targetType, int $targetId): self
    {
        return $this->copy(targetType: $targetType, targetId: $targetId);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function withChange(?array $before, ?array $after): self
    {
        return $this->copy(before: $before, after: $after);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return $this->copy(context: $context);
    }

    public function withRequest(?string $correlationId, ?string $ip, ?string $userAgent): self
    {
        return $this->copy(correlationId: $correlationId, ip: $ip, userAgent: $userAgent);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $context
     */
    private function copy(
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
        ?string $correlationId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): self {
        return new self(
            $this->actorType,
            $this->actorId,
            $this->clientId,
            $this->action,
            $targetType ?? $this->targetType,
            $targetId ?? $this->targetId,
            $before ?? $this->before,
            $after ?? $this->after,
            $context ?? $this->context,
            $correlationId ?? $this->correlationId,
            $ip ?? $this->ip,
            $userAgent ?? $this->userAgent,
        );
    }
}
