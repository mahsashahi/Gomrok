<?php

declare(strict_types=1);

use Gomrok\Http\Api\MeAction;
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
    })
        ->add(IdempotencyMiddleware::class)   // inner: runs after auth has set authClientId
        ->add(AuthenticationMiddleware::class); // outer: runs first
};
