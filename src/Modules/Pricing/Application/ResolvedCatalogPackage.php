<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Packages\Application\ResolvedPackage;

/**
 * A package in a fully resolved catalogue list (Phase 13): the Phase 11/12
 * {@see ResolvedPackage} plus its {@see ResolvedPrice}. This is what
 * `GET /api/v1/packages` returns.
 */
final readonly class ResolvedCatalogPackage
{
    public function __construct(
        public ResolvedPackage $package,
        public ResolvedPrice $price,
    ) {
    }
}
