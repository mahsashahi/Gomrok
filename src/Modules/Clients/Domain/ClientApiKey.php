<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

use DateTimeImmutable;

/**
 * A stored API key. The plaintext secret never lives here — only
 * `secretHash = sha256(secret)`, matched with a constant-time compare. `id` is
 * null until persisted.
 */
final class ClientApiKey
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly string $keyId,
        private readonly string $secretHash,
        private readonly ApiKeyPrefix $prefix,
        private readonly string $lastFour,
        private readonly ?string $label,
        private ApiKeyStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $lastUsedAt,
        private readonly ?DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $revokedAt,
        private ?int $revokedBy,
    ) {
    }

    public static function issue(
        int $clientId,
        string $keyId,
        string $secretHash,
        ApiKeyPrefix $prefix,
        string $lastFour,
        ?string $label,
        DateTimeImmutable $now,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        return new self(
            null,
            $clientId,
            $keyId,
            $secretHash,
            $prefix,
            $lastFour,
            $label,
            ApiKeyStatus::Active,
            $now,
            null,
            $expiresAt,
            null,
            null,
        );
    }

    /**
     * @param array{
     *     id: int, client_id: int, key_id: string, secret_hash: string, prefix: string,
     *     last_four: string, label: ?string, status: string, created_at: string,
     *     last_used_at: ?string, expires_at: ?string, revoked_at: ?string, revoked_by: ?int
     * } $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['id'],
            $row['client_id'],
            $row['key_id'],
            $row['secret_hash'],
            ApiKeyPrefix::from($row['prefix']),
            $row['last_four'],
            $row['label'],
            ApiKeyStatus::from($row['status']),
            new DateTimeImmutable($row['created_at']),
            $row['last_used_at'] !== null ? new DateTimeImmutable($row['last_used_at']) : null,
            $row['expires_at'] !== null ? new DateTimeImmutable($row['expires_at']) : null,
            $row['revoked_at'] !== null ? new DateTimeImmutable($row['revoked_at']) : null,
            $row['revoked_by'],
        );
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function revoke(?int $revokedBy, DateTimeImmutable $now): void
    {
        if ($this->status === ApiKeyStatus::Revoked) {
            return;
        }

        $this->status = ApiKeyStatus::Revoked;
        $this->revokedAt = $now;
        $this->revokedBy = $revokedBy;
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function matchesSecret(string $presentedSecret): bool
    {
        return hash_equals($this->secretHash, hash('sha256', $presentedSecret));
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->status === ApiKeyStatus::Active
            && ($this->expiresAt === null || $this->expiresAt > $now);
    }

    public function displayToken(): string
    {
        return \sprintf('%s_%s.%s%s', $this->prefix->value, $this->keyId, str_repeat('•', 8), $this->lastFour);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function prefix(): ApiKeyPrefix
    {
        return $this->prefix;
    }

    public function lastFour(): string
    {
        return $this->lastFour;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function status(): ApiKeyStatus
    {
        return $this->status;
    }

    public function secretHash(): string
    {
        return $this->secretHash;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedBy(): ?int
    {
        return $this->revokedBy;
    }
}
