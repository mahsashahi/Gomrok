<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\PackageSummary;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Providers\Domain\PaymentMethod;

/**
 * {@see PackageDirectory} projected off an {@see InMemoryPackageRepository} —
 * for tests that create/update a package via the real write handlers and then
 * need it visible through the read-side directory, e.g. admin orchestrator
 * handlers that chain a create/update into {@see \Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceHandler}.
 */
final class InMemoryPackageDirectory implements PackageDirectory
{
    public function __construct(private readonly InMemoryPackageRepository $packages)
    {
    }

    public function findById(int $id): ?PackageSummary
    {
        $package = $this->packages->findById($id);

        return $package !== null ? $this->toSummary($package) : null;
    }

    public function find(int $clientId, string $code): ?PackageSummary
    {
        $package = $this->packages->findByClientAndCode($clientId, $code);

        return $package !== null ? $this->toSummary($package) : null;
    }

    public function forClient(int $clientId): array
    {
        return array_map(fn (Package $p): PackageSummary => $this->toSummary($p), $this->packages->forClient($clientId));
    }

    private function toSummary(Package $package): PackageSummary
    {
        $id = $package->id();
        \assert($id !== null);

        return new PackageSummary(
            $id,
            $package->clientId(),
            $package->code()->value,
            $package->name(),
            $package->description(),
            $package->status()->value,
            $package->metadata(),
            $package->badge(),
            $package->highlighted(),
            $package->clientPackageId(),
            $package->countryCodes(),
            $package->currencyCodes(),
            array_map(static fn (PaymentMethod $m): string => $m->value, $package->methods()),
            $package->providerAccountIds(),
            array_map(static fn (PackagePurchaseCapability $c): string => $c->purchaseType->value, $package->purchaseCapabilities()),
        );
    }
}
