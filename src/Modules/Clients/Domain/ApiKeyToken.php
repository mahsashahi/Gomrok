<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * Parses a presented API-key token — `gk_live_<key_id>.<secret>` — into its
 * parts. `key_id` is 16 lowercase hex chars; the secret is URL-safe base64
 * (`A–Z a–z 0–9 - _`). Returns `null` for anything malformed; the auth path
 * (Phase 7) treats that as an auth failure without a DB hit.
 */
final readonly class ApiKeyToken
{
    private const PATTERN = '/^(gk_(?:live|test))_([0-9a-f]{16})\.([A-Za-z0-9_-]{16,128})$/';

    public function __construct(
        public ApiKeyPrefix $prefix,
        public string $keyId,
        public string $secret,
    ) {
    }

    public static function parse(string $token): ?self
    {
        if (preg_match(self::PATTERN, $token, $m) !== 1) {
            return null;
        }

        return new self(ApiKeyPrefix::from($m[1]), $m[2], $m[3]);
    }
}
