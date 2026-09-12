<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Application\ProviderAccountCredentials;

/**
 * In-memory {@see ProviderAccountCredentials} for adapter-factory tests — no
 * encryption, plain lookup by account id.
 */
final class StubProviderAccountCredentials implements ProviderAccountCredentials
{
    /** @var array<int, string> */
    private array $secrets = [];

    /** @var array<string, string> */
    private array $endpointSecrets = [];

    public function withSecret(int $accountId, string $secret): self
    {
        $this->secrets[$accountId] = $secret;

        return $this;
    }

    public function withEndpointSigningSecret(int $accountId, string $kind, string $secret): self
    {
        $this->endpointSecrets["{$accountId}:{$kind}"] = $secret;

        return $this;
    }

    public function secretFor(int $accountId): ?string
    {
        return $this->secrets[$accountId] ?? null;
    }

    public function endpointSigningSecret(int $accountId, string $endpointKind): ?string
    {
        return $this->endpointSecrets["{$accountId}:{$endpointKind}"] ?? null;
    }
}
