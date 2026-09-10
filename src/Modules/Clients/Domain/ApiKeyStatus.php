<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * API-key lifecycle. Revocation is terminal — a revoked key is never
 * reactivated; issue a new one.
 */
enum ApiKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
