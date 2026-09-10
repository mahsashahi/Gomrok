<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Modules\Providers\Domain\PaymentMethod;

/**
 * Resolves a client's catalogue for a market context (country + currency +
 * optional method): the active, sellable packages available there, each with
 * its availability narrowed to that context and its country-effective purchase
 * capabilities (Phase 12). The payment-creation flow (Phase 17) and the
 * `GET /api/v1/packages` endpoint (Phase 13) consume this.
 *
 * A package with **no** country-effective purchase capability is dropped
 * (Phase 12 Q1 — fail closed). `price` is still Phase 13.
 */
final readonly class PackageCatalog
{
    public function __construct(
        private PackageRepository $packages,
        private ProviderAccountDirectory $providerAccounts,
        private PackagePurchaseCapabilityResolver $capabilities,
    ) {
    }

    /**
     * @return list<ResolvedPackage>
     */
    public function resolve(int $clientId, string $country, string $currency, ?PaymentMethod $method = null): array
    {
        $clientAccounts = $this->providerAccounts->forClient($clientId);
        $activeAccountIds = [];
        foreach ($clientAccounts as $account) {
            if ($account->isActive()) {
                $activeAccountIds[$account->id] = true;
            }
        }

        $resolved = [];
        foreach ($this->packages->forClient($clientId) as $package) {
            if (!$package->isActive()) {
                continue;
            }
            if (!$package->availableInCountry($country) || !$package->availableInCurrency($currency)) {
                continue;
            }
            if ($method !== null && !$package->availableViaMethod($method)) {
                continue;
            }

            $capabilitySet = $this->capabilities->for($package, $country);
            if ($capabilitySet->isEmpty()) {
                continue;
            }

            $resolved[] = new ResolvedPackage(
                $this->idOf($package),
                $package->code()->value,
                $package->name(),
                $package->description(),
                $package->metadata(),
                $package->badge(),
                $package->highlighted(),
                $package->clientPackageId(),
                $this->narrowProviderAccounts($package, $clientAccounts, $activeAccountIds),
                array_map(static fn (PaymentMethod $m): string => $m->value, $package->methods()),
                $capabilitySet->capabilities,
            );
        }

        return $resolved;
    }

    /**
     * @param list<ProviderAccountSummary> $clientAccounts
     * @param array<int, true>             $activeAccountIds
     *
     * @return list<int>
     */
    private function narrowProviderAccounts(Package $package, array $clientAccounts, array $activeAccountIds): array
    {
        $packageSet = $package->providerAccountIds();

        $out = [];
        foreach ($clientAccounts as $account) {
            if (!isset($activeAccountIds[$account->id])) {
                continue;
            }
            if ($packageSet === [] || \in_array($account->id, $packageSet, true)) {
                $out[] = $account->id;
            }
        }

        return $out;
    }

    private function idOf(Package $package): int
    {
        $id = $package->id();
        \assert($id !== null);

        return $id;
    }
}
