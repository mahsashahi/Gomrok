<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PriceCatalog;
use Gomrok\Modules\Pricing\Application\ResolvedCatalogPackage;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/packages/{packageId}?country=DE[&method=card][&device=ios]`
 * (Phase 19 Q1/Q2) — one package from the same resolved catalogue
 * `GET /api/v1/packages` returns, so the two endpoints can never disagree.
 * `{packageId}` accepts either the numeric `packages.id` or the package
 * `code` (Q1). A package that exists but isn't available/sellable in the
 * requested context is reported the same as "doesn't exist" (Q2) — it simply
 * isn't in the resolved list.
 */
final readonly class PackageDetailAction
{
    public function __construct(
        private ClientContext $context,
        private PackageDirectory $packages,
        private PriceCatalog $catalog,
        private JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $clientId = $this->context->clientId();
        $ref = trim($args['packageId'] ?? '');

        $summary = ctype_digit($ref)
            ? $this->packages->findById((int) $ref)
            : $this->packages->find($clientId, $ref);
        if ($summary === null || $summary->clientId !== $clientId) {
            return $this->responder->problem($response, DomainError::notFound('package.not_found', "Package '{$ref}' was not found."));
        }

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
        foreach ($items as $item) {
            if ($item->package->id === $summary->id) {
                return $this->responder->json($response, PriceCatalog::itemToArray($item));
            }
        }

        return $this->responder->problem($response, DomainError::notFound(
            'package.not_found_in_context',
            "Package '{$ref}' is not available for country '" . strtoupper($country) . "'.",
            ['package' => $ref, 'country' => strtoupper($country)],
        ));
    }
}
