<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

/**
 * Persistence port for the {@see Package} aggregate (availability child rows
 * included). MySQL adapter in the module's `Infrastructure`.
 */
interface PackageRepository
{
    /**
     * Insert or update the package and rebuild its `package_*` availability
     * rows. Callers wrap this in a transaction.
     */
    public function save(Package $package): void;

    public function findById(int $id): ?Package;

    public function findByClientAndCode(int $clientId, string $code): ?Package;

    public function existsForClientWithCode(int $clientId, string $code): bool;

    /**
     * @return list<Package>
     */
    public function forClient(int $clientId): array;
}
