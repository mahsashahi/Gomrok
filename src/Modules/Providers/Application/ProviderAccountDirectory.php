<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

use Gomrok\Modules\Providers\Domain\ProviderAccountMode;

/**
 * Published read API for provider accounts. Other modules (routing — Phase 10,
 * admin — Phase 27) depend on this; never on the aggregate or the tables. No
 * secrets are exposed here — see {@see ProviderAccountCredentials} for the
 * decrypt path.
 */
interface ProviderAccountDirectory
{
    /**
     * @return list<ProviderAccountSummary>
     */
    public function forClient(int $clientId): array;

    public function find(int $clientId, string $slug): ?ProviderAccountSummary;

    /**
     * By id alone, no client scoping — used by {@see \Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory}
     * (Phase 21) to resolve which adapter to build for a given `provider_account_id`.
     */
    public function findById(int $id): ?ProviderAccountSummary;

    /**
     * Active accounts of the given provider type + mode — the router's candidate
     * set before country / method / capability filtering.
     *
     * @return list<ProviderAccountSummary>
     */
    public function candidates(int $clientId, string $providerTypeCode, ProviderAccountMode $mode): array;
}
