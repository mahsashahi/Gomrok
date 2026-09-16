<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * Turns a raw session token (from the admin panel's cookie) into an
 * {@see AdminAuthResult} — mirrors {@see ClientAuthenticator}. The
 * implementation lives in the Admin module (it owns sessions and account
 * status); {@see AdminAuthenticationMiddleware} depends only on this port.
 */
interface AdminAuthenticator
{
    public function authenticate(?string $token, AuthRequestMeta $meta): AdminAuthResult;
}
