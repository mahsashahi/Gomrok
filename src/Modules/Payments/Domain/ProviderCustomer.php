<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

use DateTimeImmutable;

/**
 * A durable customer identity on one provider's side, reused across many
 * payments/subscriptions for that `(client, client user)` — distinct from
 * {@see GatewayReference}, which points at a single transaction/session. `id`
 * is null until persisted.
 */
final class ProviderCustomer
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly int $providerAccountId,
        private readonly string $clientUserRef,
        private readonly string $providerCustomerId,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function link(int $clientId, int $providerAccountId, string $clientUserRef, string $providerCustomerId, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $providerAccountId, trim($clientUserRef), trim($providerCustomerId), $now, null);
    }

    public static function fromStorage(int $id, int $clientId, int $providerAccountId, string $clientUserRef, string $providerCustomerId, DateTimeImmutable $createdAt, ?DateTimeImmutable $updatedAt): self
    {
        return new self($id, $clientId, $providerAccountId, $clientUserRef, $providerCustomerId, $createdAt, $updatedAt);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function providerAccountId(): int
    {
        return $this->providerAccountId;
    }

    public function clientUserRef(): string
    {
        return $this->clientUserRef;
    }

    public function providerCustomerId(): string
    {
        return $this->providerCustomerId;
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
