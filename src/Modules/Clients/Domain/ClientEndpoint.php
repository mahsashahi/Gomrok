<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * A callback URL Gomrok posts status updates to. Belongs to a client; one active
 * endpoint per {@see EndpointPurpose}. `id` is null until persisted.
 */
final class ClientEndpoint
{
    private function __construct(
        private ?int $id,
        private readonly EndpointPurpose $purpose,
        private string $url,
        private bool $active,
    ) {
    }

    public static function register(EndpointPurpose $purpose, string $url): self
    {
        return new self(null, $purpose, $url, true);
    }

    /**
     * Rehydrate a stored row.
     */
    public static function fromStorage(int $id, EndpointPurpose $purpose, string $url, bool $active): self
    {
        return new self($id, $purpose, $url, $active);
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

    public function purpose(): EndpointPurpose
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
