<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

/**
 * Read-only view of a provider account for other modules (routing — Phase 10)
 * and the admin panel. **Carries no secret** — only `secretLastFour` and the
 * public key.
 */
final readonly class ProviderAccountSummary
{
    /**
     * @param list<string> $countries
     * @param list<string> $methods
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $slug,
        public string $name,
        public string $providerTypeCode,
        public string $mode,
        public string $status,
        public ?string $publicKey,
        public string $secretLastFour,
        public array $countries,
        public array $methods,
        public int $activeEndpointCount,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function maskedSecret(): string
    {
        return '••••' . $this->secretLastFour;
    }
}
