<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Pricing\Application\PriceListDirectory;
use Gomrok\Modules\Pricing\Application\PriceRuleDirectory;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshotRepository;
use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Domain\PriceListPackageRepository;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Modules\Pricing\Domain\PriceRuleRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoDefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPriceListDirectory;
use Gomrok\Modules\Pricing\Infrastructure\PdoPriceListPackageRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPriceListRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPriceRuleDirectory;
use Gomrok\Modules\Pricing\Infrastructure\PdoPriceRuleRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingDecisionSnapshotRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupDirectory;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupRepository;

/**
 * PHP-DI definitions for the Pricing module (Phases 13–15). `PriceResolver` /
 * `PriceCatalog` / `PriceListResolver` and the use-case handlers are autowired.
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
    PriceListRepository::class => get(PdoPriceListRepository::class),
    PriceListPackageRepository::class => get(PdoPriceListPackageRepository::class),
    PriceListDirectory::class => get(PdoPriceListDirectory::class),
    PricingDecisionSnapshotRepository::class => get(PdoPricingDecisionSnapshotRepository::class),
];
