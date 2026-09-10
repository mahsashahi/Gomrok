<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * Why an API-key authentication attempt did (or did not) pass. Recorded on
 * `client_auth_attempts`; never returned to the caller (Phase 7 Q4).
 */
enum AuthFailureReason: string
{
    case Ok = 'ok';
    case MissingAuthorization = 'missing_authorization';
    case MalformedToken = 'malformed_token';
    case UnknownKey = 'unknown_key';
    case InvalidSecret = 'invalid_secret';
    case KeyExpired = 'key_expired';
    case KeyRevoked = 'key_revoked';
    case ClientDisabled = 'client_disabled';
}
