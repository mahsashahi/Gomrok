<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * The client behind an authenticated `/api/v1` request, as seen by the HTTP
 * layer. A flat, secret-free view — modules that need more call
 * `Clients\Application\ClientDirectory` themselves. Lives in `Shared` so the
 * middleware and {@see ClientContext} do not depend on the Clients module.
 */
final readonly class AuthenticatedClient
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $status,
        public string $defaultCurrency,
        public ?string $defaultCountry,
        public string $timezone,
        public string $keyMode,
    ) {
    }
}
