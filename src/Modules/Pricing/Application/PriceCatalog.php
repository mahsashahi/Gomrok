<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Packages\Application\PackageCatalog;
use Gomrok\Modules\Packages\Application\ResolvedPurchaseCapability;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * The fully resolved catalogue for a market context: {@see PackageCatalog}
 * (availability + purchase capabilities, Phases 11–12) with each package's
 * {@see ResolvedPrice} attached (Phase 13). Backs `GET /api/v1/packages`.
 *
 * The pricing group is resolved **once**; its currency is the catalogue
 * currency. Packages priced `disabled` in that group are omitted.
 */
final readonly class PriceCatalog
{
    public function __construct(
        private PackageCatalog $packages,
        private PriceResolver $prices,
    ) {
    }

    /**
     * @return Result ok(list<ResolvedCatalogPackage>) | err({@see DomainError})
     */
    public function resolve(
        int $clientId,
        string $country,
        ?PaymentMethod $method = null,
        ?string $deviceType = null,
    ): Result {
        $group = $this->prices->resolveGroup($clientId, $country, $deviceType);
        if ($group === null) {
            return Result::err(DomainError::notFound(
                'pricing.no_pricing_group',
                "This client has no pricing group for country '" . strtoupper($country) . "' and no default group.",
                ['country' => strtoupper($country), 'device_type' => $deviceType],
            ));
        }

        $currency = $group->currencyCode();
        $items = [];
        foreach ($this->packages->resolve($clientId, $country, $currency, $method) as $package) {
            $priceResult = $this->prices->priceForGroup(
                $group,
                $package->id,
                $package->code,
                $package->name,
                $package->badge,
                $package->highlighted,
            );
            if ($priceResult->isErr()) {
                // `pricing.package_disabled_in_group` → omit; anything else (no default
                // price) → also omit from the list rather than fail the whole catalogue.
                continue;
            }

            $price = $priceResult->value();
            \assert($price instanceof ResolvedPrice);
            $items[] = new ResolvedCatalogPackage($package, $price);
        }

        usort($items, static function (ResolvedCatalogPackage $a, ResolvedCatalogPackage $b): int {
            return [$a->price->displayOrder, $a->package->code] <=> [$b->price->displayOrder, $b->package->code];
        });

        return Result::ok($items);
    }

    /**
     * @param list<ResolvedCatalogPackage> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function toArray(array $items): array
    {
        return array_map(self::itemToArray(...), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemToArray(ResolvedCatalogPackage $item): array
    {
        $p = $item->package;
        $price = $item->price;

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $price->name,
            'description' => $p->description,
            'badge' => $price->badge,
            'highlighted' => $price->highlighted,
            'client_package_id' => $p->clientPackageId,
            'price' => [
                'amount_minor' => $price->amountMinor,
                'amount' => $price->amountDecimal,
                'currency' => $price->currencyCode,
                'source' => $price->source->value,
                'pricing_group' => $price->pricingGroupSlug,
            ],
            'purchase_types' => array_map(
                static fn (ResolvedPurchaseCapability $c): array => [
                    'type' => $c->purchaseType,
                    'has_trial' => $c->hasTrial,
                    'trial_days' => $c->trialDays,
                    'duration_months' => $c->durationMonths,
                ],
                $p->purchaseCapabilities,
            ),
            'available_methods' => $p->availableMethods,
            'available_provider_account_ids' => $p->availableProviderAccountIds,
            'metadata' => $p->metadata,
        ];
    }
}
