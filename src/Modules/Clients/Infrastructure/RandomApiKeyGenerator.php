<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\ApiKeyGenerator;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\GeneratedApiKey;
use Gomrok\Shared\Application\TokenGenerator;

/**
 * Mints `gk_<mode>_<key_id>.<secret>` — `key_id` = 8 random bytes hex (16 chars),
 * `secret` = 24 random bytes URL-safe base64 (~32 chars, ~192 bits). Only
 * `sha256(secret)` and the last four chars are kept.
 */
final readonly class RandomApiKeyGenerator implements ApiKeyGenerator
{
    private const KEY_ID_BYTES = 8;
    private const SECRET_BYTES = 24;

    public function __construct(private TokenGenerator $tokens)
    {
    }

    public function generate(
        int $clientId,
        ApiKeyPrefix $prefix,
        ?string $label,
        DateTimeImmutable $now,
        ?DateTimeImmutable $expiresAt = null,
    ): GeneratedApiKey {
        $keyId = $this->tokens->hex(self::KEY_ID_BYTES);
        $secret = $this->tokens->urlSafe(self::SECRET_BYTES);

        $token = \sprintf('%s_%s.%s', $prefix->value, $keyId, $secret);

        $apiKey = ClientApiKey::issue(
            $clientId,
            $keyId,
            hash('sha256', $secret),
            $prefix,
            substr($secret, -4),
            $label,
            $now,
            $expiresAt,
        );

        return new GeneratedApiKey($token, $apiKey);
    }
}
