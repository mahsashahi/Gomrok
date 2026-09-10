<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application;

/**
 * Membership checks against the Phase 4 reference tables (`currencies`,
 * `countries`). Lets a use case reject an unknown currency/country with a clean
 * validation error instead of leaving it to a foreign-key violation.
 */
interface ReferenceCatalog
{
    public function currencyExists(string $code): bool;

    /**
     * True when the country is one of Gomrok's configured markets (`countries`),
     * not merely a well-formed ISO code.
     */
    public function countryExists(string $code): bool;
}
