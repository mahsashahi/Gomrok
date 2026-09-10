<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\RevokeApiKey;

final readonly class RevokeApiKeyCommand
{
    public function __construct(
        public string $keyId,
        public ?int $revokedBy = null,
    ) {
    }
}
