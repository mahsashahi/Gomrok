<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Pricing\Application\PriceCatalog;
use Gomrok\Modules\Pricing\Application\ResolvedCatalogPackage;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/packages?country=DE[&method=card][&device=ios]` — the resolved
 * catalogue for the authenticated client: availability + purchase capabilities
 * (Phases 11–12) with each package's resolved price (Phase 13). Currency is the
 * matched pricing group's. Gomrok never trusts a client-supplied price.
 */
final readonly class PackagesAction
{
    public function __construct(
        private ClientContext $context,
        private PriceCatalog $catalog,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $clientId = $this->context->clientId();
        $query = $request->getQueryParams();

        $country = \is_string($query['country'] ?? null) ? trim($query['country']) : '';
        if ($country === '') {
            return $this->responder->problem($response, DomainError::validation('packages.country_required', 'A "country" query parameter is required.'));
        }

        $method = null;
        if (\is_string($query['method'] ?? null) && $query['method'] !== '') {
            $method = PaymentMethod::tryFrom($query['method']);
            if ($method === null) {
                return $this->responder->problem($response, DomainError::validation('packages.unknown_method', "Unknown payment method '{$query['method']}'.", ['method' => $query['method']]));
            }
        }

        $device = \is_string($query['device'] ?? null) && $query['device'] !== '' ? $query['device'] : null;

        $result = $this->catalog->resolve($clientId, $country, $method, $device);
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        /** @var list<ResolvedCatalogPackage> $items */
        $items = $result->value();

        return $this->responder->json($response, [
            'country' => strtoupper($country),
            'currency' => $items[0]->price->currencyCode ?? null,
            'packages' => PriceCatalog::toArray($items),
        ]);
    }
}
