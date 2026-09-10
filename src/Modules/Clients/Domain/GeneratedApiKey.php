<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * The result of minting a key: the one-time plaintext token to hand back to the
 * caller (never stored, never logged) and the {@see ClientApiKey} to persist.
 */
final readonly class GeneratedApiKey
{
    public function __construct(
        public string $plaintextToken,
        public ClientApiKey $apiKey,
    ) {
    }
}
