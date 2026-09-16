<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * The admin user behind an authenticated `/admin` request, as seen by the
 * HTTP layer — mirrors {@see AuthenticatedClient}. A flat, secret-free view.
 */
final readonly class AuthenticatedAdmin
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $role,
    ) {
    }
}
