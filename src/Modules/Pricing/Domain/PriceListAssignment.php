<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;

/**
 * A visitor's persisted bucket within one pricing group's A/B experiment
 * (Phase 15 Q4/Q5, decided at Phase 24 Q6/Q7). `visitorRefHash` is
 * `SHA-256(pricingGroupId . ':' . visitorRef)` — the raw `visitorRef` is
 * never stored. `id` is null until persisted.
 */
final class PriceListAssignment
{
    private function __construct(
        private ?int $id,
        private readonly int $clientId,
        private readonly int $pricingGroupId,
        private readonly string $visitorRefHash,
        private int $priceListId,
        private readonly DateTimeImmutable $assignedAt,
        private ?DateTimeImmutable $reassignedAt,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function assign(int $clientId, int $pricingGroupId, string $visitorRefHash, int $priceListId, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $pricingGroupId, $visitorRefHash, $priceListId, $now, null, $now, null);
    }

    public static function fromStorage(
        int $id,
        int $clientId,
        int $pricingGroupId,
        string $visitorRefHash,
        int $priceListId,
        DateTimeImmutable $assignedAt,
        ?DateTimeImmutable $reassignedAt,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $clientId, $pricingGroupId, $visitorRefHash, $priceListId, $assignedAt, $reassignedAt, $createdAt, $updatedAt);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    /** The originally/currently assigned list became disabled — move to the group's control list. */
    public function reassignTo(int $priceListId, DateTimeImmutable $now): void
    {
        $this->priceListId = $priceListId;
        $this->reassignedAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function pricingGroupId(): int
    {
        return $this->pricingGroupId;
    }

    public function visitorRefHash(): string
    {
        return $this->visitorRefHash;
    }

    public function priceListId(): int
    {
        return $this->priceListId;
    }

    public function assignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function reassignedAt(): ?DateTimeImmutable
    {
        return $this->reassignedAt;
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
