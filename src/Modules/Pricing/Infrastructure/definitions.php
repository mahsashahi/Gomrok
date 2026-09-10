<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Pricing\Application\PriceRuleDirectory;
use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Domain\PriceRuleRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoDefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPriceRuleDirectory;
use Gomrok\Modules\Pricing\Infrastructure\PdoPriceRuleRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupDirectory;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupRepository;

/**
 * PHP-DI definitions for the Pricing module (Phase 13). `PriceResolver` /
 * `PriceCatalog` and the use-case handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    PricingGroupRepository::class => get(PdoPricingGroupRepository::class),
    PricingGroupPackageRepository::class => get(PdoPricingGroupPackageRepository::class),
    DefaultPackagePriceRepository::class => get(PdoDefaultPackagePriceRepository::class),
    ClientExchangeRateRepository::class => get(PdoClientExchangeRateRepository::class),
    PricingGroupDirectory::class => get(PdoPricingGroupDirectory::class),
    PriceRuleRepository::class => get(PdoPriceRuleRepository::class),
    PriceRuleDirectory::class => get(PdoPriceRuleDirectory::class),
];
