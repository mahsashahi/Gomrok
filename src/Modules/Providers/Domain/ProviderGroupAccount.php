<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * One entry in a {@see ProviderGroup}'s ordered provider-account list
 * (`provider_group_accounts`). `priority` ascending = tried first; `isEnabled`
 * false parks the entry without losing its place. `id` is null until persisted.
 */
final class ProviderGroupAccount
{
    private function __construct(
        private ?int $id,
        private readonly int $providerAccountId,
        private int $priority,
        private bool $isEnabled,
    ) {
    }

    public static function link(int $providerAccountId, int $priority, bool $isEnabled = true): self
    {
        return new self(null, $providerAccountId, max(0, $priority), $isEnabled);
    }

    public static function fromStorage(int $id, int $providerAccountId, int $priority, bool $isEnabled): self
    {
        return new self($id, $providerAccountId, $priority, $isEnabled);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function providerAccountId(): int
    {
        return $this->providerAccountId;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }
}
