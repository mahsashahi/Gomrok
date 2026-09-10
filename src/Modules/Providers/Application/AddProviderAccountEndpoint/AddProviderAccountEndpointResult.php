<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint;

/**
 * `token` is the URL segment for `/api/v1/webhooks/{provider}/{token}` — surface
 * it once so the client can register it with the provider.
 */
final readonly class AddProviderAccountEndpointResult
{
    public function __construct(
        public int $endpointId,
        public string $kind,
        public ?string $token,
        public ?string $inboundPath,
    ) {
    }
}
