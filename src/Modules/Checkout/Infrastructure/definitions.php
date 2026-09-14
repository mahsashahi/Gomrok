<?php

declare(strict_types=1);

use function DI\factory;
use function DI\get;

use Gomrok\Config\Settings;
use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn\ConfirmCheckoutReturnHandler;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutHandler;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Infrastructure\PdoCheckoutAttemptDirectory;
use Gomrok\Modules\Checkout\Infrastructure\PdoCheckoutAttemptRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;

/**
 * PHP-DI definitions for the Checkout module (Phase 18 — the pre-payment
 * lifecycle anchor). Use-case handlers are autowired, except
 * {@see CreateProviderCheckoutHandler} / {@see ConfirmCheckoutReturnHandler}
 * (Phase 24), which need plain string settings `Settings` carries — not
 * autowirable by type.
 *
 * @return array<string, mixed>
 */
return [
    CheckoutAttemptRepository::class => get(PdoCheckoutAttemptRepository::class),
    CheckoutAttemptDirectory::class => get(PdoCheckoutAttemptDirectory::class),
    CreateProviderCheckoutHandler::class => factory(static function (
        CheckoutAttemptRepository $attempts,
        ProviderRoutingDecisionSnapshotRepository $routingSnapshots,
        ResolveCheckoutPayableAmount $payableAmount,
        PackageDirectory $packages,
        ProviderAdapterFactory $adapterFactory,
        GatewayReferenceRepository $gatewayReferences,
        AuditLogWriter $audit,
        Transactions $transactions,
        ClockInterface $clock,
        Settings $settings,
    ): CreateProviderCheckoutHandler {
        return new CreateProviderCheckoutHandler(
            $attempts,
            $routingSnapshots,
            $payableAmount,
            $packages,
            $adapterFactory,
            $gatewayReferences,
            $audit,
            $transactions,
            $clock,
            $settings->appBaseUrl,
            $settings->checkoutReturnTokenSecret,
        );
    }),
    ConfirmCheckoutReturnHandler::class => factory(static function (
        CheckoutAttemptRepository $attempts,
        ReconcileCheckoutStatusHandler $reconcile,
        ClientDirectory $clients,
        Settings $settings,
    ): ConfirmCheckoutReturnHandler {
        return new ConfirmCheckoutReturnHandler(
            $attempts,
            $reconcile,
            $clients,
            $settings->checkoutReturnTokenSecret,
        );
    }),
];
