<?php

declare(strict_types=1);

use Gomrok\Http\Api\MeAction;
use Gomrok\Http\Api\PackageDetailAction;
use Gomrok\Http\Api\PackagesAction;
use Gomrok\Http\Api\PricingResolveAction;
use Gomrok\Http\Api\VouchersValidateAction;
use Gomrok\Http\HealthAction;
use Gomrok\Shared\Http\AuthenticationMiddleware;
use Gomrok\Shared\Http\IdempotencyMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // Public — no authentication (connectivity probe; does no I/O, returns no sensitive data).
    $app->get('/health', HealthAction::class);

    // Every /api/v1 route is authenticated (Bearer API key) and, for writes, idempotent.
    $app->group('/api/v1', function (RouteCollectorProxy $group): void {
        $group->get('/me', MeAction::class);
        $group->get('/packages', PackagesAction::class);
        $group->get('/packages/{packageId}', PackageDetailAction::class);
        // A pure read (no side effects); GET so it isn't caught by the write-idempotency rule.
        $group->get('/pricing/resolve', PricingResolveAction::class);
        // Same reasoning (Phase 19 Q4): a non-locking preview, never a reservation.
        $group->get('/vouchers/validate', VouchersValidateAction::class);
    })
        ->add(IdempotencyMiddleware::class)   // inner: runs after auth has set authClientId
        ->add(AuthenticationMiddleware::class); // outer: runs first
};
