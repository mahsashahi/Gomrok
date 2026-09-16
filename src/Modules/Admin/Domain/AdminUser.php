<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

use DateTimeImmutable;

/**
 * A person who can sign into the admin panel (Phase 27). `passwordHash` is
 * PHP's own `password_hash()` output (bcrypt/argon2i, PHP's `PASSWORD_DEFAULT`)
 * — a deliberately slow hash, unlike {@see \Gomrok\Modules\Clients\Domain\ClientApiKey}'s
 * fast `sha256`, because a password is low-entropy and user-chosen while an
 * API secret is high-entropy and random. `id` is null until persisted.
 */
final class AdminUser
{
    private function __construct(
        private ?int $id,
        private readonly string $name,
        private readonly string $email,
        private string $passwordHash,
        private readonly AdminRole $role,
        private AdminUserStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $name,
        string $email,
        string $passwordHash,
        AdminRole $role,
        DateTimeImmutable $now,
    ): self {
        return new self(
            null,
            trim($name),
            self::normaliseEmail($email),
            $passwordHash,
            $role,
            AdminUserStatus::Active,
            $now,
            null,
        );
    }

    public static function fromStorage(
        int $id,
        string $name,
        string $email,
        string $passwordHash,
        AdminRole $role,
        AdminUserStatus $status,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $name, $email, $passwordHash, $role, $status, $createdAt, $updatedAt);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function matchesPassword(string $presented): bool
    {
        return password_verify($presented, $this->passwordHash);
    }

    public function isUsable(): bool
    {
        return $this->status === AdminUserStatus::Active;
    }

    public function setPasswordHash(string $passwordHash, DateTimeImmutable $now): void
    {
        $this->passwordHash = $passwordHash;
        $this->updatedAt = $now;
    }

    public function setStatus(AdminUserStatus $status, DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function role(): AdminRole
    {
        return $this->role;
    }

    public function status(): AdminUserStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
