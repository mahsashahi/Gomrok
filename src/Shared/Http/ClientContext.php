<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use LogicException;

/**
 * Holds the authenticated client for the current request. One instance per
 * request (DI singleton, like {@see \Gomrok\Shared\Infrastructure\CorrelationId}).
 * {@see AuthenticationMiddleware} populates it inside the `/api/v1` group;
 * repositories and query services read it to scope every query to the client.
 *
 * Public routes (`/health`, …) never populate it — `isAuthenticated()` stays
 * false and the accessors throw.
 */
final class ClientContext
{
    private ?AuthenticatedClient $client = null;

    public function set(AuthenticatedClient $client): void
    {
        $this->client = $client;
    }

    public function isAuthenticated(): bool
    {
        return $this->client !== null;
    }

    public function client(): AuthenticatedClient
    {
        return $this->client ?? throw new LogicException(
            'No authenticated client in context — reached outside the authenticated API group.',
        );
    }

    public function clientId(): int
    {
        return $this->client()->id;
    }
}
