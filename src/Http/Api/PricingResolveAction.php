<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/pricing/resolve?package=<code>&country=DE[&device=ios][&method=card][&purchase_type=subscription][&interval=yearly]`
 * — the resolved price for one package in a full checkout context (Phase 13 base
 * + Phase 14 dimension overrides). A pure read; the client never supplies a
 * price. (CLAUDE.md suggests `POST`; a `GET` keeps it clear of the
 * write-idempotency rule.)
 */
final readonly class PricingResolveAction
{
    public function __construct(
        private ClientContext $context,
        private PackageDirectory $packages,
        private PriceResolver $resolver,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $clientId = $this->context->clientId();
        $query = $request->getQueryParams();

        $code = \is_string($query['package'] ?? null) ? trim($query['package']) : '';
        $country = \is_string($query['country'] ?? null) ? trim($query['country']) : '';
        $device = \is_string($query['device'] ?? null) && $query['device'] !== '' ? $query['device'] : null;

        if ($code === '' || $country === '') {
            return $this->responder->problem($response, DomainError::validation('pricing.missing_fields', '"package" and "country" query parameters are required.'));
        }

        $method = null;
        if (\is_string($query['method'] ?? null) && $query['method'] !== '') {
            $method = PaymentMethod::tryFrom($query['method']);
            if ($method === null) {
                return $this->responder->problem($response, DomainError::validation('pricing.unknown_method', "Unknown payment method '{$query['method']}'.", ['method' => $query['method']]));
            }
        }

        $purchaseType = null;
        if (\is_string($query['purchase_type'] ?? null) && $query['purchase_type'] !== '') {
            $purchaseType = PurchaseType::tryFrom($query['purchase_type']);
            if ($purchaseType === null) {
                return $this->responder->problem($response, DomainError::validation('pricing.unknown_purchase_type', "Unknown purchase type '{$query['purchase_type']}'.", ['purchase_type' => $query['purchase_type']]));
            }
        }

        $interval = null;
        if (\is_string($query['interval'] ?? null) && $query['interval'] !== '') {
            $interval = SubscriptionInterval::tryFrom($query['interval']);
            if ($interval === null) {
                return $this->responder->problem($response, DomainError::validation('pricing.unknown_interval', "Unknown subscription interval '{$query['interval']}'.", ['interval' => $query['interval']]));
            }
        }

        $package = $this->packages->find($clientId, $code);
        if ($package === null) {
            return $this->responder->problem($response, DomainError::notFound('package.not_found', "Package '{$code}' was not found.", ['package' => $code]));
        }

        $result = $this->resolver->resolve($clientId, $package->id, $country, $device, $method, $purchaseType, $interval);
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $price = $result->value();
        \assert($price instanceof ResolvedPrice);

        return $this->responder->json($response, [
            'package' => $price->packageCode,
            'name' => $price->name,
            'badge' => $price->badge,
            'highlighted' => $price->highlighted,
            'price' => [
                'amount_minor' => $price->amountMinor,
                'amount' => $price->amountDecimal,
                'currency' => $price->currencyCode,
                'source' => $price->source->value,
                'pricing_group' => $price->pricingGroupSlug,
                'applied_rule_id' => $price->appliedRuleId,
                'applied_dimensions' => $price->appliedDimensions,
            ],
        ]);
    }
}
