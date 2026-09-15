<?php

declare(strict_types=1);

use Gomrok\Http\Api\MeAction;
use Gomrok\Http\Api\PackageDetailAction;
use Gomrok\Http\Api\PackagesAction;
use Gomrok\Http\Api\PaymentsCancelAction;
use Gomrok\Http\Api\PaymentsCaptureAction;
use Gomrok\Http\Api\PaymentsCreateAction;
use Gomrok\Http\Api\PaymentsRefundAction;
use Gomrok\Http\Api\PaymentsReturnAction;
use Gomrok\Http\Api\PaymentsShowAction;
use Gomrok\Http\Api\PaymentsStatusAction;
use Gomrok\Http\Api\PricingResolveAction;
use Gomrok\Http\Api\SubscriptionsCancelAction;
use Gomrok\Http\Api\SubscriptionsCreateAction;
use Gomrok\Http\Api\SubscriptionsShowAction;
use Gomrok\Http\Api\VouchersValidateAction;
use Gomrok\Http\Api\WebhooksReceiveAction;
use Gomrok\Http\HealthAction;
use Gomrok\Shared\Http\AuthenticationMiddleware;
use Gomrok\Shared\Http\IdempotencyMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // Public — no authentication (connectivity probe; does no I/O, returns no sensitive data).
    $app->get('/health', HealthAction::class);

    // Public — no API key exists at this point (Phase 24 Q2): the customer's own
    // browser lands here after leaving the provider's hosted checkout page.
    $app->get('/payments/return', PaymentsReturnAction::class);

    // Public — no API key exists here either (Phase 25 Q5): a provider's own
    // signature, verified via the opaque {token}, is the authentication.
    $app->post('/api/v1/webhooks/{provider}/{token}', WebhooksReceiveAction::class);

    // Every /api/v1 route is authenticated (Bearer API key) and, for writes, idempotent.
    $app->group('/api/v1', function (RouteCollectorProxy $group): void {
        $group->get('/me', MeAction::class);
        $group->get('/packages', PackagesAction::class);
        $group->get('/packages/{packageId}', PackageDetailAction::class);
        // A pure read (no side effects); GET so it isn't caught by the write-idempotency rule.
        $group->get('/pricing/resolve', PricingResolveAction::class);
        // Same reasoning (Phase 19 Q4): a non-locking preview, never a reservation.
        $group->get('/vouchers/validate', VouchersValidateAction::class);
        // Phase 24 — the end-to-end payment creation flow.
        $group->post('/payments', PaymentsCreateAction::class);
        $group->get('/payments/{id}', PaymentsShowAction::class);
        $group->get('/payments/{id}/status', PaymentsStatusAction::class);
        $group->post('/payments/{id}/cancel', PaymentsCancelAction::class);
        $group->post('/payments/{id}/refund', PaymentsRefundAction::class);
        $group->post('/payments/{id}/capture', PaymentsCaptureAction::class);
        // Phase 26 — the end-to-end subscription creation flow.
        $group->post('/subscriptions', SubscriptionsCreateAction::class);
        $group->get('/subscriptions/{id}', SubscriptionsShowAction::class);
        $group->post('/subscriptions/{id}/cancel', SubscriptionsCancelAction::class);
    })
        ->add(IdempotencyMiddleware::class)   // inner: runs after auth has set authClientId
        ->add(AuthenticationMiddleware::class); // outer: runs first
};
