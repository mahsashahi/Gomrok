<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

use DateTimeImmutable;

/**
 * Mints a fresh API key (random `key_id` + secret, hashed). The implementation
 * needs a CSPRNG, so it lives in `Infrastructure`; use cases depend on this
 * port.
 */
interface ApiKeyGenerator
{
    public function generate(
        int $clientId,
        ApiKeyPrefix $prefix,
        ?string $label,
        DateTimeImmutable $now,
        ?DateTimeImmutable $expiresAt = null,
    ): GeneratedApiKey;
}
