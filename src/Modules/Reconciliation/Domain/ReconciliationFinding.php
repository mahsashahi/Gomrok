<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Domain;

use DateTimeImmutable;

/**
 * One detected drift between Gomrok's local payment/subscription status and
 * what the provider actually reports when polled (Phase 29). Detection
 * only — nothing here ever transitions the payment/subscription itself; see
 * `db_explain.md` → "Background jobs, reconciliation & observability" for
 * why. `resolvedAt`/`resolvedBy` marks a finding *reviewed*, not *fixed* —
 * the same convention `error_logs` (Phase 27) uses. `id` is null until
 * persisted.
 */
final class ReconciliationFinding
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly ReconciliationTargetType $targetType,
        private readonly int $targetId,
        private readonly string $localStatus,
        private readonly string $providerStatusRaw,
        private readonly ?string $mappedProviderStatus,
        private readonly DateTimeImmutable $detectedAt,
        private ?DateTimeImmutable $resolvedAt,
        private ?int $resolvedBy,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function detect(
        int $clientId,
        ReconciliationTargetType $targetType,
        int $targetId,
        string $localStatus,
        string $providerStatusRaw,
        ?string $mappedProviderStatus,
        DateTimeImmutable $now,
    ): self {
        return new self(null, $clientId, $targetType, $targetId, $localStatus, $providerStatusRaw, $mappedProviderStatus, $now, null, null, $now);
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        ReconciliationTargetType $targetType,
        int $targetId,
        string $localStatus,
        string $providerStatusRaw,
        ?string $mappedProviderStatus,
        DateTimeImmutable $detectedAt,
        ?DateTimeImmutable $resolvedAt,
        ?int $resolvedBy,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $clientId, $targetType, $targetId, $localStatus, $providerStatusRaw, $mappedProviderStatus, $detectedAt, $resolvedAt, $resolvedBy, $createdAt);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function markResolved(int $adminUserId, DateTimeImmutable $now): void
    {
        $this->resolvedAt = $now;
        $this->resolvedBy = $adminUserId;
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function targetType(): ReconciliationTargetType
    {
        return $this->targetType;
    }

    public function targetId(): int
    {
        return $this->targetId;
    }

    public function localStatus(): string
    {
        return $this->localStatus;
    }

    public function providerStatusRaw(): string
    {
        return $this->providerStatusRaw;
    }

    public function mappedProviderStatus(): ?string
    {
        return $this->mappedProviderStatus;
    }

    public function detectedAt(): DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function resolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolvedBy(): ?int
    {
        return $this->resolvedBy;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
