<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\PackageSummary;
use Gomrok\Modules\Pricing\Application\PriceListDirectory;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceListSummary;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Builds the Packaging &amp; Pricing screen's "Pricing groups" tab (Phase 27
 * Increment A — read-only) — mirrors {@see PackagesTabHandler}'s reuse of
 * the real Pricing resolution services. "Providers" per group is a derived
 * readout (active provider accounts whose configured countries intersect
 * the group's) — Gomrok's pricing groups don't carry a providers field of
 * their own; that's Provider routing groups' job (Phase 10), a separate
 * concept this cross-references for admin convenience.
 */
final readonly class GroupsTabHandler
{
    public function __construct(
        private ClientDirectory $clients,
        private PackageDirectory $packages,
        private PricingGroupRepository $pricingGroups,
        private PriceListDirectory $priceLists,
        private PriceResolver $priceResolver,
        private PriceListResolver $priceListResolver,
        private ProviderAccountDirectory $providerAccounts,
        private ClientExchangeRateRepository $exchangeRates,
        private DefaultPackagePriceRepository $defaultPrices,
        private PricingGroupPackageRepository $groupPackages,
        private ClockInterface $clock,
    ) {
    }

    public function forClient(int $clientId, ?string $selectedSlug, ?int $selectedPriceListId): GroupsTabResult
    {
        $groups = $this->pricingGroups->forClient($clientId);

        $selectedGroup = null;
        foreach ($groups as $group) {
            if ($group->slug()->value === $selectedSlug) {
                $selectedGroup = $group;

                break;
            }
        }
        $selectedGroup ??= $groups[0] ?? null;

        $items = array_map(
            fn (PricingGroup $g): GroupListItem => new GroupListItem(
                $g->slug()->value,
                $g->name(),
                $g->isDefault() ? 'All other countries' : implode(', ', $g->countryCodes()),
                $g->isDefault(),
                $selectedGroup !== null && $g->slug()->value === $selectedGroup->slug()->value,
            ),
            $groups,
        );

        $detail = $selectedGroup !== null ? $this->buildDetail($selectedGroup, $selectedPriceListId) : null;

        return new GroupsTabResult($items, $detail);
    }

    private function buildDetail(PricingGroup $group, ?int $selectedPriceListId): GroupDetail
    {
        $client = $this->clients->findById($group->clientId());
        $defaultCurrency = $client !== null ? $client->defaultCurrency : 'USD';

        $groupId = $group->id();
        \assert($groupId !== null);

        $lists = $this->priceLists->forGroup($groupId);
        $enabledCount = \count(array_filter($lists, static fn (PriceListSummary $l): bool => $l->isEnabled));

        $control = null;
        foreach ($lists as $list) {
            if ($list->isControl) {
                $control = $list;
            }
        }

        // What the user clicked (for card highlighting) can be a disabled
        // list — {@see \Gomrok\Modules\Pricing\Application\PriceListResolver}'s
        // own fallback semantics say a disabled list is never actually used
        // for pricing, so the resolved list (what drives the math and the
        // table header below) is tracked separately and may differ.
        $requested = $selectedPriceListId !== null ? $this->findList($lists, $selectedPriceListId) : null;
        $selectedListId = $requested !== null ? $requested->id : ($control !== null ? $control->id : null);

        $resolvedList = ($requested !== null && $requested->isEnabled) ? $requested : $control;
        $resolvedListId = $resolvedList !== null ? $resolvedList->id : null;
        $activeListName = $resolvedList !== null ? $resolvedList->name : 'Control';
        $activeListIsControl = $resolvedList === null || $resolvedList->isControl;

        $priceListItems = array_map(
            fn (PriceListSummary $l): PriceListItem => new PriceListItem(
                $l->id,
                $l->name,
                $l->isControl,
                $l->isEnabled,
                $l->isEnabled ? $this->shareLabel($enabledCount) : 'Disabled — no new visitors assigned',
                $l->id === $selectedListId,
            ),
            $lists,
        );

        $packages = $this->packages->forClient($group->clientId());
        $displayOrder = [];
        foreach ($this->groupPackages->forGroup($groupId) as $explicitRow) {
            $displayOrder[$explicitRow->packageId()] = $explicitRow->displayOrder();
        }

        $packageRows = [];
        foreach ($packages as $package) {
            $row = $this->rowForPackage($group, $package, $resolvedListId, $defaultCurrency);
            if ($row !== null) {
                $packageRows[] = $row;
            }
        }

        // A package with no explicit `pricing_group_packages` row is an
        // implicit "default", sorted after every explicitly ordered package
        // (matches {@see \Gomrok\Modules\Pricing\Domain\PricingGroupPackage}'s
        // own docblock) — ties keep the packages' natural (catalogue) order,
        // since PHP's usort is not guaranteed stable pre-8.0 but is from 8.0+.
        usort(
            $packageRows,
            static fn (GroupPackageRow $a, GroupPackageRow $b): int => ($displayOrder[$a->packageId] ?? \PHP_INT_MAX) <=> ($displayOrder[$b->packageId] ?? \PHP_INT_MAX),
        );

        return new GroupDetail(
            $groupId,
            $group->slug()->value,
            $group->name(),
            $group->isDefault() ? 'All other countries' : implode(', ', $group->countryCodes()),
            $group->countryCodes(),
            $group->currencyCode(),
            $group->isDefault(),
            $group->isActive(),
            $this->providersServing($group),
            $priceListItems,
            $resolvedListId,
            $activeListName,
            $activeListIsControl,
            $packageRows,
        );
    }

    /**
     * @param list<PriceListSummary> $lists
     */
    private function findList(array $lists, int $listId): ?PriceListSummary
    {
        foreach ($lists as $list) {
            if ($list->id === $listId) {
                return $list;
            }
        }

        return null;
    }

    private function shareLabel(int $enabledCount): string
    {
        if ($enabledCount <= 1) {
            return '100% of visitors';
        }

        return '~' . (int) round(100 / $enabledCount) . '% of visitors';
    }

    private function rowForPackage(PricingGroup $group, PackageSummary $package, ?int $priceListId, string $defaultCurrency): ?GroupPackageRow
    {
        $groupId = $group->id();
        if ($groupId === null) {
            return null;
        }

        $result = $this->priceResolver->priceForGroup($group, $package->id, $package->code, $package->name, $package->badge, $package->highlighted);
        if ($result->isErr()) {
            return null;
        }

        $resolved = $result->value();
        \assert($resolved instanceof ResolvedPrice);
        $resolved = $this->priceListResolver->apply($groupId, $package->id, $priceListId, $resolved);

        $price = Money::fromMinor($resolved->amountMinor, Currency::of($resolved->currencyCode));
        $defaultCurrencyPrice = $this->convert($price, $defaultCurrency, $package->clientId);
        $defaultCurrencyPriceLabel = $defaultCurrencyPrice !== null ? $defaultCurrencyPrice->format('en_US') : null;

        $override = $this->groupPackages->find($groupId, $package->id);

        return new GroupPackageRow(
            $package->id,
            $package->code,
            $package->name,
            $this->defaultPriceLabel($package),
            $price->format('en_US'),
            $defaultCurrencyPriceLabel,
            $package->status,
            $package->highlighted,
            $package->badge,
            $override !== null ? $override->status()->value : 'default',
            $override !== null ? $override->amountMinor() : null,
        );
    }

    private function defaultPriceLabel(PackageSummary $package): ?string
    {
        $default = $this->defaultPrices->find($package->id);
        if ($default === null) {
            return null;
        }

        return Money::fromMinor($default->amountMinor, Currency::of($default->currencyCode))->format('en_US');
    }

    private function convert(Money $amount, string $targetCurrencyCode, int $clientId): ?Money
    {
        if ($amount->currency()->code() === strtoupper($targetCurrencyCode)) {
            return null;
        }

        $target = Currency::of($targetCurrencyCode);
        $rate = $this->exchangeRates->findRate($clientId, $amount->currency()->code(), $target->code(), $this->clock->now());
        if ($rate === null) {
            return null;
        }

        return $amount->convertTo($target, $rate->rate);
    }

    private function providersServing(PricingGroup $group): string
    {
        $accounts = $this->providerAccounts->forClient($group->clientId());
        $names = [];
        foreach ($accounts as $account) {
            if (!$account->isActive()) {
                continue;
            }
            if ($group->isDefault() || $account->countries === [] || array_intersect($account->countries, $group->countryCodes()) !== []) {
                $names[] = $account->name;
            }
        }

        return $names === [] ? '—' : implode(', ', $names);
    }
}
