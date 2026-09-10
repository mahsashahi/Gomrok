<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\CreateClient;

use Gomrok\Modules\Clients\Domain\Events\ApiKeyIssued;
use Gomrok\Modules\Clients\Domain\Events\ClientCreated;

/**
 * Success payload for {@see CreateClientHandler}. `plaintextApiKey` is the only
 * time the token is ever available — the caller must surface it and not store it.
 */
final readonly class CreateClientResult
{
    public function __construct(
        public int $clientId,
        public string $slug,
        public string $keyId,
        public string $plaintextApiKey,
        public ClientCreated $clientCreated,
        public ApiKeyIssued $apiKeyIssued,
    ) {
    }
}
