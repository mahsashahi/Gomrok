<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

use DateTimeImmutable;

/**
 * Where one package exists on one provider account's side (Phase 12 Q3) — a
 * Stripe Product/Price, a PayPal plan, or nothing (`NotNeeded`). One row per
 * linked `(package, provider account)`, created lazily. Phase 12 owns the state
 * transitions and accepts a manually-entered `remoteId`; the provider-API
 * creation path is wired per adapter in Phases 21–23.
 */
final class PackageProviderDefinition
{
    private function __construct(
        private ?int $id,
        private readonly int $packageId,
        private readonly int $providerAccountId,
        private ?string $providerSideName,
        private ?string $remoteId,
        private PackageProviderSyncState $syncState,
        private ?DateTimeImmutable $lastSyncedAt,
        private ?string $lastError,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function link(
        int $packageId,
        int $providerAccountId,
        ?string $providerSideName,
        ?string $remoteId,
        DateTimeImmutable $now,
    ): self {
        $remoteId = self::trimToNull($remoteId);

        return new self(
            null,
            $packageId,
            $providerAccountId,
            self::trimToNull($providerSideName),
            $remoteId,
            $remoteId !== null ? PackageProviderSyncState::Synced : PackageProviderSyncState::NotCreated,
            $remoteId !== null ? $now : null,
            null,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        int $packageId,
        int $providerAccountId,
        ?string $providerSideName,
        ?string $remoteId,
        PackageProviderSyncState $syncState,
        ?DateTimeImmutable $lastSyncedAt,
        ?string $lastError,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $packageId,
            $providerAccountId,
            $providerSideName,
            $remoteId,
            $syncState,
            $lastSyncedAt,
            $lastError,
            $createdAt,
            $updatedAt,
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function rename(?string $providerSideName, DateTimeImmutable $now): void
    {
        $this->providerSideName = self::trimToNull($providerSideName);
        $this->updatedAt = $now;
    }

    /**
     * Attach a remote id (manually now, provider-API later) → `Synced`.
     */
    public function markSynced(string $remoteId, DateTimeImmutable $now): void
    {
        $trimmed = trim($remoteId);
        if ($trimmed === '') {
            return;
        }
        $this->remoteId = $trimmed;
        $this->syncState = PackageProviderSyncState::Synced;
        $this->lastSyncedAt = $now;
        $this->lastError = null;
        $this->updatedAt = $now;
    }

    public function markNotNeeded(DateTimeImmutable $now): void
    {
        $this->syncState = PackageProviderSyncState::NotNeeded;
        $this->remoteId = null;
        $this->lastError = null;
        $this->updatedAt = $now;
    }

    /**
     * The local package changed — a `Synced` definition becomes `Drift`. No-op
     * for `NotCreated` / `NotNeeded` / already-`Drift`.
     */
    public function markDrift(DateTimeImmutable $now): bool
    {
        if ($this->syncState !== PackageProviderSyncState::Synced) {
            return false;
        }
        $this->syncState = PackageProviderSyncState::Drift;
        $this->updatedAt = $now;

        return true;
    }

    public function recordError(string $message, DateTimeImmutable $now): void
    {
        $this->lastError = mb_substr(trim($message), 0, 255);
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function packageId(): int
    {
        return $this->packageId;
    }

    public function providerAccountId(): int
    {
        return $this->providerAccountId;
    }

    public function providerSideName(): ?string
    {
        return $this->providerSideName;
    }

    public function remoteId(): ?string
    {
        return $this->remoteId;
    }

    public function syncState(): PackageProviderSyncState
    {
        return $this->syncState;
    }

    public function lastSyncedAt(): ?DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
