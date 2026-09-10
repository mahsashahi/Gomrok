<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * Request metadata the authenticator records against an auth attempt.
 */
final readonly class AuthRequestMeta
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $correlationId = null,
    ) {
    }
}
