<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

/**
 * Published read API for packages — the raw catalogue rows without market
 * resolution. Other modules and the CLI depend on this, never on the aggregate
 * or the tables. {@see PackageCatalog} is the market-resolved view.
 */
interface PackageDirectory
{
    public function findById(int $id): ?PackageSummary;

    public function find(int $clientId, string $code): ?PackageSummary;

    /**
     * @return list<PackageSummary>
     */
    public function forClient(int $clientId): array;
}
