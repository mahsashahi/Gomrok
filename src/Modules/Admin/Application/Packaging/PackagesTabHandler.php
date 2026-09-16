<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\PackageProviderDefinitionDirectory;
use Gomrok\Modules\Packages\Application\PackageProviderDefinitionSummary;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Packages\Application\PackageSummary;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Builds the Packaging &amp; Pricing screen's "Packages" tab (Phase 27
 * Increment A — read-only). Reuses the real Pricing resolution services
 * ({@see PriceResolver::priceForGroup()} + {@see PriceListResolver::apply()})
 * rather than re-deriving prices, so the numbers shown here always match
 * what a customer would actually be charged.
 */
final readonly class PackagesTabHandler
{
    public function __construct(
        private ClientDirectory $clients,
        private PackageDirectory $packages,
        private PackagePurchaseCapabilityResolver $capabilities,
        private PackageProviderDefinitionDirectory $providerDefinitions,
        private ProviderAccountDirectory $providerAccounts,
        private PricingGroupRepository $pricingGroups,
        private PriceResolver $priceResolver,
        private PriceListResolver $priceListResolver,
        private ClientExchangeRateRepository $exchangeRates,
        private DefaultPackagePriceRepository $defaultPrices,
        private ClockInterface $clock,
    ) {
    }

    public function forClient(int $clientId, ?string $selectedCode): PackagesTabResult
    {
        $client = $this->clients->findById($clientId);
        $defaultCurrency = $client !== null ? $client->defaultCurrency : 'USD';

        $packages = $this->packages->forClient($clientId);
        $items = array_map(fn (PackageSummary $p): PackageListItem => $this->toListItem($p, $p->code === $selectedCode), $packages);

        $selectedSummary = null;
        foreach ($packages as $package) {
            if ($package->code === $selectedCode) {
                $selectedSummary = $package;

                break;
            }
        }
        $selectedSummary ??= $packages[0] ?? null;

        $detail = $selectedSummary !== null ? $this->buildDetail($selectedSummary, $defaultCurrency) : null;

        return new PackagesTabResult($items, $detail);
    }

    private function toListItem(PackageSummary $package, bool $selected): PackageListItem
    {
        return new PackageListItem(
            $package->code,
            $package->name,
            $this->defaultPriceLabel($package),
            $this->durationLabel($package),
            $this->trialLabel($package),
            $package->badge,
            $package->highlighted,
            $package->status,
            $selected,
        );
    }

    private function buildDetail(PackageSummary $package, string $defaultCurrency): PackageDetail
    {
        $providerSetup = array_map(
            fn (PackageProviderDefinitionSummary $def): ProviderSetupRow => new ProviderSetupRow(
                $this->providerAccountName($def->providerAccountId),
                $this->providerAccountType($def->providerAccountId),
                $def->syncState,
                $def->providerSideName,
                $def->remoteId,
            ),
            $this->providerDefinitions->forPackage($package->id),
        );

        $groups = $this->pricingGroups->forClient($package->clientId);
        $byGroupRows = [];
        foreach ($groups as $group) {
            $row = $this->rowForGroup($group, $package, $defaultCurrency);
            if ($row !== null) {
                $byGroupRows[] = $row;
            }
        }

        $set = $this->capabilities->forId($package->id);
        $oneTime = $set->for(PurchaseType::OneTimePayment);
        $subscription = $set->for(PurchaseType::Subscription);
        $durationMonths = $oneTime !== null ? $oneTime->durationMonths : ($subscription !== null ? $subscription->durationMonths : null);

        $default = $this->defaultPrices->find($package->id);

        return new PackageDetail(
            $package->id,
            $package->clientId,
            $package->code,
            $package->name,
            $package->description,
            $package->badge,
            $package->highlighted,
            $this->durationLabel($package),
            $this->defaultPriceLabel($package),
            $this->trialLabel($package),
            $package->status,
            $providerSetup,
            $byGroupRows,
            $oneTime !== null,
            $subscription !== null,
            $subscription !== null && $subscription->hasTrial,
            $subscription?->trialDays,
            $durationMonths,
            $default?->amountMinor,
            $default?->currencyCode,
            $package->isActive(),
        );
    }

    private function rowForGroup(PricingGroup $group, PackageSummary $package, string $defaultCurrency): ?PackagingByGroupRow
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
        $resolved = $this->priceListResolver->apply($groupId, $package->id, null, $resolved);

        $price = Money::fromMinor($resolved->amountMinor, Currency::of($resolved->currencyCode));
        $defaultCurrencyPrice = $this->convert($price, $defaultCurrency, $package->clientId);

        $countryLabel = $group->isDefault() ? 'All other countries' : implode(', ', $group->countryCodes());
        $providersLabel = $this->providersServing($group);

        return new PackagingByGroupRow(
            $group->slug()->value,
            $group->name(),
            $countryLabel,
            $price->format('en_US'),
            $defaultCurrencyPrice?->format('en_US'),
            $group->isActive() ? 'Active' : 'Disabled',
            $providersLabel,
        );
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

    private function providerAccountName(int $providerAccountId): string
    {
        $account = $this->providerAccounts->findById($providerAccountId);

        return $account !== null ? $account->name : "#{$providerAccountId}";
    }

    private function providerAccountType(int $providerAccountId): string
    {
        $account = $this->providerAccounts->findById($providerAccountId);

        return $account !== null ? $account->providerTypeCode : '—';
    }

    private function defaultPriceLabel(PackageSummary $package): ?string
    {
        $default = $this->defaultPrices->find($package->id);
        if ($default === null) {
            return null;
        }

        return Money::fromMinor($default->amountMinor, Currency::of($default->currencyCode))->format('en_US');
    }

    private function durationLabel(PackageSummary $package): string
    {
        $set = $this->capabilities->forId($package->id);
        foreach ($set->types() as $type) {
            $capability = $set->for(PurchaseType::from($type));
            if ($capability?->durationMonths !== null) {
                return $capability->durationMonths === 1 ? '1 month' : "{$capability->durationMonths} months";
            }
        }

        return \in_array(PurchaseType::Subscription->value, $set->types(), true) ? 'Recurring' : '—';
    }

    private function trialLabel(PackageSummary $package): ?string
    {
        $set = $this->capabilities->forId($package->id);
        $subscription = $set->for(PurchaseType::Subscription);
        if ($subscription === null || !$subscription->hasTrial || $subscription->trialDays === null) {
            return null;
        }

        return "{$subscription->trialDays}-day trial";
    }
}
