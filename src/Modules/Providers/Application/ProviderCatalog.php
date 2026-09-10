<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

/**
 * The Providers module's published read API — the provider types Gomrok
 * integrates with, plus their declared capabilities and purchase types. Other
 * modules depend on this, not on the repository or the `provider_*` tables.
 */
interface ProviderCatalog
{
    /**
     * @return list<ProviderTypeSummary>
     */
    public function all(): array;

    public function find(string $code): ?ProviderTypeSummary;
}
