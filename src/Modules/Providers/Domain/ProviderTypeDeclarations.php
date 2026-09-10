<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Read port for provider-type capability declarations. The MySQL adapter joins
 * `provider_types` with `provider_type_capabilities` / `provider_type_purchase_types`.
 */
interface ProviderTypeDeclarations
{
    public function findByCode(string $providerTypeCode): ?ProviderTypeDeclaration;

    /**
     * @return list<ProviderTypeDeclaration>
     */
    public function all(): array;
}
