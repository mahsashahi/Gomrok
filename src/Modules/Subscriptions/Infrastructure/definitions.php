<?php

declare(strict_types=1);

use function DI\autowire;

use Gomrok\Config\Settings;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Subscriptions\Application\Jobs\MollieSubscriptionActivationScanHandler;
use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEventRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLinkRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionEventRepository;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionPaymentLinkRepository;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionRepository;
use Psr\Clock\ClockInterface;

use function DI\factory;
use function DI\get;

/**
 * PHP-DI definitions for the Subscriptions module (Phase 26; Phase 29 added
 * the Mollie activation job handler). Use-case handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    SubscriptionRepository::class => get(PdoSubscriptionRepository::class),
    SubscriptionEventRepository::class => get(PdoSubscriptionEventRepository::class),
    SubscriptionPaymentLinkRepository::class => get(PdoSubscriptionPaymentLinkRepository::class),
    SubscriptionDirectory::class => get(PdoSubscriptionDirectory::class),

    MollieSubscriptionActivationScanHandler::class => factory(static function (
        SubscriptionRepository $subscriptions,
        GatewayReferenceRepository $gatewayReferences,
        ProviderAccountRepository $providerAccounts,
        PackageDirectory $packages,
        ProviderAdapterFactory $adapterFactory,
        ClockInterface $clock,
        Settings $settings,
    ): MollieSubscriptionActivationScanHandler {
        return new MollieSubscriptionActivationScanHandler(
            $subscriptions,
            $gatewayReferences,
            $providerAccounts,
            $packages,
            $adapterFactory,
            $clock,
            $settings->appBaseUrl,
        );
    }),
];
