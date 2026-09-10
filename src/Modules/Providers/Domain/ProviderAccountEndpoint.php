<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * One inbound-message endpoint of a {@see ProviderAccount}. `id` is null until
 * persisted.
 */
final class ProviderAccountEndpoint
{
    private function __construct(
        private ?int $id,
        private readonly EndpointKind $kind,
        private readonly ?string $token,
        private ?string $signingSecretCiphertext,
        private bool $active,
    ) {
    }

    public static function create(EndpointKind $kind, ?string $token, ?string $signingSecretCiphertext): self
    {
        return new self(null, $kind, $token, $signingSecretCiphertext, true);
    }

    public static function fromStorage(int $id, EndpointKind $kind, ?string $token, ?string $signingSecretCiphertext, bool $active): self
    {
        return new self($id, $kind, $token, $signingSecretCiphertext, $active);
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function replaceSigningSecret(?string $ciphertext): void
    {
        $this->signingSecretCiphertext = $ciphertext;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function kind(): EndpointKind
    {
        return $this->kind;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function signingSecretCiphertext(): ?string
    {
        return $this->signingSecretCiphertext;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
