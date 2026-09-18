<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

/**
 * A provider account's own override callback URL for one purpose (Phase 28
 * Q1, user-specified hybrid) — used instead of the client's default
 * `client_endpoints` URL only when that specific account produced the
 * notified event. `id` is null until persisted.
 */
final class ProviderAccountNotificationOverride
{
    private function __construct(
        private ?int $id,
        private readonly int $providerAccountId,
        private readonly string $purpose,
        private string $url,
        private bool $active,
    ) {
    }

    public static function register(int $providerAccountId, string $purpose, string $url): self
    {
        return new self(null, $providerAccountId, $purpose, $url, true);
    }

    public static function fromStorage(int $id, int $providerAccountId, string $purpose, string $url, bool $active): self
    {
        return new self($id, $providerAccountId, $purpose, $url, $active);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function changeUrl(string $url): void
    {
        $this->url = $url;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function providerAccountId(): int
    {
        return $this->providerAccountId;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
