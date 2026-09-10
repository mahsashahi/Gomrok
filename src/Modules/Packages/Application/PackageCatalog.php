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
 * optional method): the active packages available there, each with its
 * availability narrowed to that context. The payment-creation flow (Phase 17)
 * and the `GET /api/v1/packages` endpoint (Phase 13) consume this.
 *
 * Phase 11 carries the catalogue identity + market-narrowed providers / methods.
 * `price` (Phase 13) and `purchaseTypes` (Phase 12) are not resolved yet.
 */
final readonly class PackageCatalog
{
    public function __construct(
        private PackageRepository $packages,
        private ProviderAccountDirectory $providerAccounts,
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

            $resolved[] = new ResolvedPackage(
                $this->idOf($package),
                $package->code()->value,
                $package->name(),
                $package->description(),
                $package->metadata(),
                $this->narrowProviderAccounts($package, $clientAccounts, $activeAccountIds),
                array_map(static fn (PaymentMethod $m): string => $m->value, $package->methods()),
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
