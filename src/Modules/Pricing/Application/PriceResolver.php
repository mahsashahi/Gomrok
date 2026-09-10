<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Money;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Resolves the price for a package in a market context:
 *   1. pricing group match (Phase 13 — priority, device, `is_default` last);
 *   2. group-package row status (Phase 13 — `disabled` → unavailable);
 *   3. base amount (Phase 13 — baseline / client-rate conversion / group override);
 *   4. most-specific matching `price_rules` row (Phase 14) — an available rule
 *      overrides the amount (`source = dimension_override`); an unavailable rule
 *      → hard `pricing.combination_unavailable`, never a fallback.
 */
final readonly class PriceResolver
{
    public function __construct(
        private PricingGroupRepository $groups,
        private PricingGroupPackageRepository $rows,
        private DefaultPackagePriceRepository $defaults,
        private ClientExchangeRateRepository $rates,
        private PackageDirectory $packages,
        private PriceRuleResolver $priceRules,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Result ok({@see ResolvedPrice}) | err({@see DomainError})
     */
    public function resolve(
        int $clientId,
        int $packageId,
        string $country,
        ?string $deviceType = null,
        ?PaymentMethod $method = null,
        ?PurchaseType $purchaseType = null,
        ?SubscriptionInterval $interval = null,
        ?int $providerAccountId = null,
    ): Result {
        $package = $this->packages->findById($packageId);
        if ($package === null || $package->clientId !== $clientId) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$packageId} was not found for this client."));
        }

        $group = $this->resolveGroup($clientId, $country, $deviceType);
        if ($group === null) {
            return Result::err(DomainError::notFound(
                'pricing.no_pricing_group',
                "This client has no pricing group for country '{$country}' and no default group.",
                ['country' => strtoupper($country), 'device_type' => $deviceType],
            ));
        }

        $base = $this->priceForGroup($group, $packageId, $package->code, $package->name, $package->badge, $package->highlighted);
        if ($base->isErr()) {
            return $base;
        }

        $resolved = $base->value();
        \assert($resolved instanceof ResolvedPrice);
        $groupId = $group->id();
        \assert($groupId !== null);

        return $this->applyRules($resolved, $clientId, $packageId, new PriceRuleContext(
            $groupId,
            strtoupper(trim($country)),
            $group->currencyCode(),
            $providerAccountId,
            $method,
            $purchaseType,
            $interval,
        ));
    }

    /**
     * @return Result ok({@see ResolvedPrice}) | err({@see DomainError})
     */
    private function applyRules(ResolvedPrice $base, int $clientId, int $packageId, PriceRuleContext $context): Result
    {
        $rule = $this->priceRules->resolve($clientId, $packageId, $context);
        if ($rule === null) {
            return Result::ok($base);
        }

        if (!$rule->isAvailable()) {
            return Result::err(DomainError::unsupported(
                'pricing.combination_unavailable',
                "Package '{$base->packageCode}' is not available for this combination.",
                [
                    'package' => $base->packageCode,
                    'pinned' => implode(',', $rule->pinnedDimensions()),
                    'rule_id' => $rule->id(),
                ],
            ));
        }

        $amountMinor = $rule->amountMinor();
        \assert($amountMinor !== null);
        $money = Money::fromMinor($amountMinor, Currency::of($base->currencyCode));
        $ruleId = $rule->id();
        \assert($ruleId !== null);

        return Result::ok($base->withRule($amountMinor, $money->amount(), $ruleId, $rule->pinnedDimensions()));
    }

    public function resolveGroup(int $clientId, string $country, ?string $deviceType): ?PricingGroup
    {
        $country = strtoupper(trim($country));

        $default = null;
        foreach ($this->orderedGroups($clientId) as $group) {
            if (!$group->isActive() || !$group->appliesToDevice($deviceType)) {
                continue;
            }
            if ($group->isDefault()) {
                $default ??= $group;

                continue;
            }
            if ($group->coversCountry($country)) {
                return $group;
            }
        }

        return $default;
    }

    /**
     * @return Result ok({@see ResolvedPrice}) | err({@see DomainError})
     */
    public function priceForGroup(
        PricingGroup $group,
        int $packageId,
        string $packageCode,
        string $packageName,
        ?string $packageBadge,
        bool $packageHighlighted,
    ): Result {
        $groupId = $group->id();
        \assert($groupId !== null);

        $row = $this->rows->find($groupId, $packageId);
        $status = $row?->status() ?? PricingRowStatus::Default;

        if ($status === PricingRowStatus::Disabled) {
            return Result::err(DomainError::unsupported(
                'pricing.package_disabled_in_group',
                "Package '{$packageCode}' is not sold in pricing group '{$group->slug()->value}'.",
                ['package' => $packageCode, 'pricing_group' => $group->slug()->value],
            ));
        }

        $name = $row?->nameOverride() ?? $packageName;
        $badge = $row?->badgeOverride() ?? $packageBadge;
        $highlighted = $row?->highlightedOverride() ?? $packageHighlighted;
        $displayOrder = $row?->displayOrder() ?? 0;

        if ($status === PricingRowStatus::Override) {
            $amountMinor = $row?->amountMinor();
            $currency = $row?->currencyCode();
            \assert($amountMinor !== null && $currency !== null);

            return $this->finalPrice($packageId, $packageCode, $amountMinor, $currency, PriceSource::GroupOverride, $group, $name, $badge, $highlighted, $displayOrder);
        }

        $baseline = $this->defaults->find($packageId);
        if ($baseline === null) {
            return Result::err(DomainError::notFound(
                'pricing.no_default_price',
                "Package '{$packageCode}' has no default price.",
                ['package' => $packageCode],
            ));
        }

        if ($baseline->currencyCode === $group->currencyCode()) {
            return $this->finalPrice($packageId, $packageCode, $baseline->amountMinor, $baseline->currencyCode, PriceSource::Baseline, $group, $name, $badge, $highlighted, $displayOrder);
        }

        $rate = $this->rates->findRate($group->clientId(), $baseline->currencyCode, $group->currencyCode(), $this->clock->now());
        if ($rate === null) {
            return Result::err(DomainError::validation(
                'pricing.no_exchange_rate',
                "No {$baseline->currencyCode}->{$group->currencyCode()} exchange rate is configured for this client.",
                ['base' => $baseline->currencyCode, 'quote' => $group->currencyCode()],
            ));
        }

        $converted = Money::fromMinor($baseline->amountMinor, Currency::of($baseline->currencyCode))
            ->convertTo(Currency::of($group->currencyCode()), $rate->rate);

        return $this->finalPrice($packageId, $packageCode, $converted->toMinor(), $group->currencyCode(), PriceSource::Converted, $group, $name, $badge, $highlighted, $displayOrder);
    }

    private function finalPrice(
        int $packageId,
        string $packageCode,
        int $amountMinor,
        string $currencyCode,
        PriceSource $source,
        PricingGroup $group,
        string $name,
        ?string $badge,
        bool $highlighted,
        int $displayOrder,
    ): Result {
        $money = Money::fromMinor($amountMinor, Currency::of($currencyCode));

        return Result::ok(new ResolvedPrice(
            $packageId,
            $packageCode,
            $amountMinor,
            $money->amount(),
            $currencyCode,
            $source,
            $group->slug()->value,
            $group->isDefault(),
            $name,
            $badge,
            $highlighted,
            $displayOrder,
        ));
    }

    /**
     * @return list<PricingGroup>
     */
    private function orderedGroups(int $clientId): array
    {
        $groups = $this->groups->forClient($clientId);
        usort($groups, static function (PricingGroup $a, PricingGroup $b): int {
            return [$a->isDefault() ? 1 : 0, $a->priority(), $a->slug()->value]
                <=> [$b->isDefault() ? 1 : 0, $b->priority(), $b->slug()->value];
        });

        return $groups;
    }
}
