<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\AuthRequestMeta;
use Gomrok\Shared\Http\AuthResult;
use Gomrok\Shared\Http\ClientAuthenticator;

/**
 * A {@see ClientAuthenticator} that returns a fixed outcome, for middleware /
 * routing tests. Records the last header and meta it was called with.
 */
final class StubClientAuthenticator implements ClientAuthenticator
{
    public ?string $lastHeader = null;
    public ?AuthRequestMeta $lastMeta = null;

    public function __construct(private AuthResult $result)
    {
    }

    public static function succeedingAs(AuthenticatedClient $client): self
    {
        return new self(AuthResult::success($client));
    }

    public static function unauthorized(): self
    {
        return new self(AuthResult::unauthorized());
    }

    public static function clientDisabled(): self
    {
        return new self(AuthResult::clientDisabled());
    }

    public function authenticate(?string $authorizationHeader, AuthRequestMeta $meta): AuthResult
    {
        $this->lastHeader = $authorizationHeader;
        $this->lastMeta = $meta;

        return $this->result;
    }
}
