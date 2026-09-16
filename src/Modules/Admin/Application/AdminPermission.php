<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application;

/**
 * Every admin permission key CLAUDE.md's "Admin Panel Role-Based Permission
 * Requirement" section lists. Checked at both the UI layer (hiding an action)
 * and the backend/API layer (rejecting it) — CLAUDE.md: "Hiding a button in
 * the UI is not enough. Every admin action must be checked on the backend."
 */
enum AdminPermission: string
{
    case ClientsView = 'clients.view';
    case ClientsCreate = 'clients.create';
    case ClientsUpdate = 'clients.update';
    case ClientApiKeysView = 'client_api_keys.view';
    case ClientApiKeysCreate = 'client_api_keys.create';
    case ClientApiKeysRevoke = 'client_api_keys.revoke';
    case PackagesView = 'packages.view';
    case PackagesCreate = 'packages.create';
    case PackagesUpdate = 'packages.update';
    case PackagesDisable = 'packages.disable';
    case PricingView = 'pricing.view';
    case PricingCreate = 'pricing.create';
    case PricingUpdate = 'pricing.update';
    case CountryOverridesView = 'country_overrides.view';
    case CountryOverridesCreate = 'country_overrides.create';
    case CountryOverridesUpdate = 'country_overrides.update';
    case VouchersView = 'vouchers.view';
    case VouchersCreate = 'vouchers.create';
    case VouchersUpdate = 'vouchers.update';
    case VouchersDisable = 'vouchers.disable';
    case VoucherRedemptionsView = 'voucher_redemptions.view';
    case ProviderConfigsView = 'provider_configs.view';
    case ProviderConfigsCreate = 'provider_configs.create';
    case ProviderConfigsUpdate = 'provider_configs.update';
    case PaymentsView = 'payments.view';
    case PaymentsRefund = 'payments.refund';
    case PaymentsCancel = 'payments.cancel';
    case PaymentsCapture = 'payments.capture';
    case SubscriptionsView = 'subscriptions.view';
    case SubscriptionsCancel = 'subscriptions.cancel';
    case WebhooksView = 'webhooks.view';
    case WebhooksReplay = 'webhooks.replay';
    case NotificationsView = 'notifications.view';
    case NotificationsRetry = 'notifications.retry';
    case JobsView = 'jobs.view';
    case JobsRetry = 'jobs.retry';
    case AuditLogsView = 'audit_logs.view';
    case ErrorLogsView = 'error_logs.view';
    /**
     * Not in CLAUDE.md's suggested permission list, added for the "mark
     * resolved" action `.claude/docs/database-design.md`'s `error_logs`
     * table was already designed for (`resolved_at`/`resolved_by`) — the
     * same "separate write permission beyond `.view`" shape CLAUDE.md's own
     * list already uses for `webhooks.replay`, `jobs.retry`, and
     * `notifications.retry`.
     */
    case ErrorLogsResolve = 'error_logs.resolve';
    case ReconciliationView = 'reconciliation.view';
    case AdminUsersView = 'admin_users.view';
    case AdminUsersCreate = 'admin_users.create';
    case AdminUsersUpdate = 'admin_users.update';
    case AdminUsersDisable = 'admin_users.disable';
}
