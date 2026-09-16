<?php

declare(strict_types=1);

use Gomrok\Http\Admin\AdminActiveClientAction;
use Gomrok\Http\Admin\AdminAdminUserPasswordResetAction;
use Gomrok\Http\Admin\AdminAdminUsersAction;
use Gomrok\Http\Admin\AdminAdminUsersCreateAction;
use Gomrok\Http\Admin\AdminAdminUsersStatusAction;
use Gomrok\Http\Admin\AdminAuditLogsAction;
use Gomrok\Http\Admin\AdminClientApiKeyIssueAction;
use Gomrok\Http\Admin\AdminClientApiKeyRevokeAction;
use Gomrok\Http\Admin\AdminClientsAction;
use Gomrok\Http\Admin\AdminClientsCreateAction;
use Gomrok\Http\Admin\AdminClientsStatusAction;
use Gomrok\Http\Admin\AdminClientsUpdateAction;
use Gomrok\Http\Admin\AdminCustomersAction;
use Gomrok\Http\Admin\AdminErrorLogResolutionAction;
use Gomrok\Http\Admin\AdminErrorLogsAction;
use Gomrok\Http\Admin\AdminGroupPackagePriceAction;
use Gomrok\Http\Admin\AdminGroupReorderAction;
use Gomrok\Http\Admin\AdminGroupsCreateAction;
use Gomrok\Http\Admin\AdminGroupsUpdateAction;
use Gomrok\Http\Admin\AdminHomeAction;
use Gomrok\Http\Admin\AdminLoginShowAction;
use Gomrok\Http\Admin\AdminLoginSubmitAction;
use Gomrok\Http\Admin\AdminLogoutAction;
use Gomrok\Http\Admin\AdminPackageProviderLinkAction;
use Gomrok\Http\Admin\AdminPackagesCreateAction;
use Gomrok\Http\Admin\AdminPackagesUpdateAction;
use Gomrok\Http\Admin\AdminPackagingAction;
use Gomrok\Http\Admin\AdminPriceListPackagePriceAction;
use Gomrok\Http\Admin\AdminPriceListsCreateAction;
use Gomrok\Http\Admin\AdminPriceListsStatusAction;
use Gomrok\Http\Admin\AdminProviderAccountRotateSecretAction;
use Gomrok\Http\Admin\AdminProviderAccountsCreateAction;
use Gomrok\Http\Admin\AdminProviderAccountsUpdateAction;
use Gomrok\Http\Admin\AdminProviderGroupAccountAddAction;
use Gomrok\Http\Admin\AdminProviderGroupAccountRemoveAction;
use Gomrok\Http\Admin\AdminProviderGroupAccountToggleAction;
use Gomrok\Http\Admin\AdminProviderGroupReorderAction;
use Gomrok\Http\Admin\AdminProviderGroupsCreateAction;
use Gomrok\Http\Admin\AdminProviderGroupsUpdateAction;
use Gomrok\Http\Admin\AdminProvidersAction;
use Gomrok\Http\Admin\AdminSalesAction;
use Gomrok\Http\Admin\AdminSettingsAction;
use Gomrok\Http\Admin\AdminVoucherCurrencyDiscountAction;
use Gomrok\Http\Admin\AdminVoucherCurrencyDiscountRemoveAction;
use Gomrok\Http\Admin\AdminVoucherEligibilityAction;
use Gomrok\Http\Admin\AdminVouchersAction;
use Gomrok\Http\Admin\AdminVouchersCreateAction;
use Gomrok\Http\Admin\AdminVoucherStatusAction;
use Gomrok\Http\Admin\AdminVouchersUpdateAction;
use Gomrok\Http\Admin\AdminVoucherUsageLimitsAction;
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
use Gomrok\Shared\Http\AdminAuthenticationMiddleware;
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

    // Public — the admin sign-in form itself (Phase 27); everything else under
    // /admin is authenticated via the session cookie group below.
    $app->get('/admin/login', AdminLoginShowAction::class);
    $app->post('/admin/login', AdminLoginSubmitAction::class);
    $app->post('/admin/logout', AdminLogoutAction::class);

    // Every /admin route (besides the two above) requires a valid admin session cookie.
    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('', AdminHomeAction::class);
        $group->post('/active-client', AdminActiveClientAction::class);
        $group->get('/sales', AdminSalesAction::class);
        $group->get('/customers', AdminCustomersAction::class);
        $group->get('/packaging', AdminPackagingAction::class);

        // Phase 27 Increment B — Packaging & Pricing write endpoints.
        $group->post('/packaging/packages', AdminPackagesCreateAction::class);
        $group->post('/packaging/packages/{packageId}', AdminPackagesUpdateAction::class);
        $group->post('/packaging/packages/{packageId}/provider', AdminPackageProviderLinkAction::class);
        $group->post('/packaging/groups', AdminGroupsCreateAction::class);
        $group->post('/packaging/groups/{groupId}', AdminGroupsUpdateAction::class);
        $group->post('/packaging/groups/{groupId}/packages/{packageId}/price', AdminGroupPackagePriceAction::class);
        $group->post('/packaging/groups/{groupId}/reorder', AdminGroupReorderAction::class);
        $group->post('/packaging/price-lists', AdminPriceListsCreateAction::class);
        $group->post('/packaging/price-lists/{priceListId}/status', AdminPriceListsStatusAction::class);
        $group->post('/packaging/price-lists/{priceListId}/packages/{packageId}/price', AdminPriceListPackagePriceAction::class);

        // Phase 27 — Providers screen (full read+write in one pass, Q1).
        $group->get('/providers', AdminProvidersAction::class);
        $group->post('/providers/accounts', AdminProviderAccountsCreateAction::class);
        $group->post('/providers/accounts/{accountId}', AdminProviderAccountsUpdateAction::class);
        $group->post('/providers/accounts/{accountId}/rotate-secret', AdminProviderAccountRotateSecretAction::class);
        $group->post('/providers/groups', AdminProviderGroupsCreateAction::class);
        $group->post('/providers/groups/{groupId}', AdminProviderGroupsUpdateAction::class);
        $group->post('/providers/groups/{groupId}/accounts', AdminProviderGroupAccountAddAction::class);
        $group->post('/providers/groups/{groupId}/accounts/{accountId}/remove', AdminProviderGroupAccountRemoveAction::class);
        $group->post('/providers/groups/{groupId}/accounts/{accountId}/toggle', AdminProviderGroupAccountToggleAction::class);
        $group->post('/providers/groups/{groupId}/reorder', AdminProviderGroupReorderAction::class);

        // Phase 27 — Vouchers screen (full read+write in one pass).
        $group->get('/vouchers', AdminVouchersAction::class);
        $group->post('/vouchers', AdminVouchersCreateAction::class);
        $group->post('/vouchers/{voucherId}', AdminVouchersUpdateAction::class);
        $group->post('/vouchers/{voucherId}/status', AdminVoucherStatusAction::class);
        $group->post('/vouchers/{voucherId}/eligibility', AdminVoucherEligibilityAction::class);
        $group->post('/vouchers/{voucherId}/usage-limits', AdminVoucherUsageLimitsAction::class);
        $group->post('/vouchers/{voucherId}/currency-discounts', AdminVoucherCurrencyDiscountAction::class);
        $group->post('/vouchers/{voucherId}/currency-discounts/{currency}/remove', AdminVoucherCurrencyDiscountRemoveAction::class);

        // Phase 27 — Clients screen (stat tabs + New client modal, full read+write).
        $group->get('/clients', AdminClientsAction::class);
        $group->post('/clients', AdminClientsCreateAction::class);
        $group->post('/clients/{clientId}', AdminClientsUpdateAction::class);
        $group->post('/clients/{clientId}/status', AdminClientsStatusAction::class);
        $group->post('/clients/{clientId}/api-keys', AdminClientApiKeyIssueAction::class);
        $group->post('/clients/{clientId}/api-keys/{keyId}/revoke', AdminClientApiKeyRevokeAction::class);

        // Phase 27 — Admin Users screen (full read+write).
        $group->get('/admin-users', AdminAdminUsersAction::class);
        $group->post('/admin-users', AdminAdminUsersCreateAction::class);
        $group->post('/admin-users/{adminUserId}/status', AdminAdminUsersStatusAction::class);
        $group->post('/admin-users/{adminUserId}/reset-password', AdminAdminUserPasswordResetAction::class);

        // Phase 27 — Audit Logs screen (read-only by design; see AuditLogsScreenHandler).
        $group->get('/audit-logs', AdminAuditLogsAction::class);

        // Phase 27 — Error Logs screen (real "mark resolved"/"reopen" write action).
        $group->get('/error-logs', AdminErrorLogsAction::class);
        $group->post('/error-logs/{errorLogId}/resolution', AdminErrorLogResolutionAction::class);

        // Phase 27 — Settings: no backing domain/schema exists (see AdminSettingsAction);
        // a neutral placeholder per Phases.md's "undesigned screens" rule.
        $group->get('/settings', AdminSettingsAction::class);
    })
        ->add(AdminAuthenticationMiddleware::class);

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
