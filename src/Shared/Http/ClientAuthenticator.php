<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

/**
 * Turns a raw `Authorization` header into an {@see AuthResult}. The
 * implementation lives in the Clients module (it owns keys and client status);
 * {@see AuthenticationMiddleware} depends only on this port.
 *
 * The implementation also records the attempt (success and failure) and
 * throttles `client_api_keys.last_used_at` — those are side effects of
 * `authenticate()`, not the middleware's job.
 */
interface ClientAuthenticator
{
    public function authenticate(?string $authorizationHeader, AuthRequestMeta $meta): AuthResult;
}
