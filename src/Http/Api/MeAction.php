<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/me` — echoes the authenticated client. Lets a client verify a key
 * and see which market defaults / key mode Gomrok resolved. No secrets.
 */
final readonly class MeAction
{
    public function __construct(
        private ClientContext $context,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $client = $this->context->client();

        return $this->responder->json($response, [
            'id' => $client->id,
            'slug' => $client->slug,
            'name' => $client->name,
            'status' => $client->status,
            'default_currency' => $client->defaultCurrency,
            'default_country' => $client->defaultCountry,
            'timezone' => $client->timezone,
            'key_mode' => $client->keyMode,
        ]);
    }
}
