<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

use DateTimeImmutable;

/**
 * A DB-backed admin login session (Phase 27 Q3). The browser holds the raw
 * token in an `HttpOnly` cookie; only `tokenHash = sha256(token)` — matching
 * {@see \Gomrok\Modules\Clients\Domain\ClientApiKey}'s existing
 * hash-and-compare pattern for a high-entropy random value — is ever
 * persisted. `id` is null until persisted.
 */
final class AdminSession
{
    private function __construct(
        private ?int $id,
        private readonly int $adminUserId,
        private readonly string $tokenHash,
        private readonly ?string $ip,
        private readonly ?string $userAgent,
        private readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $revokedAt,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $lastUsedAt,
    ) {
    }

    public static function issue(
        int $adminUserId,
        string $tokenHash,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): self {
        return new self(null, $adminUserId, $tokenHash, $ip, $userAgent, $expiresAt, null, $now, null);
    }

    public static function fromStorage(
        int $id,
        int $adminUserId,
        string $tokenHash,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $revokedAt,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $lastUsedAt,
    ): self {
        return new self($id, $adminUserId, $tokenHash, $ip, $userAgent, $expiresAt, $revokedAt, $createdAt, $lastUsedAt);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function isValid(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $now;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function touch(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function adminUserId(): int
    {
        return $this->adminUserId;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }
}
