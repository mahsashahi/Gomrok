<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application;

use Gomrok\Modules\Subscriptions\Domain\Subscription;

/**
 * Before/after row snapshot for {@see \Gomrok\Shared\Application\Audit\AuditEntry::withChange()},
 * mirroring `PaymentAuditSnapshot`.
 */
final readonly class SubscriptionAuditSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id(),
            'client_id' => $subscription->clientId(),
            'client_user_ref' => $subscription->clientUserRef(),
            'checkout_attempt_id' => $subscription->checkoutAttemptId(),
            'package_id' => $subscription->packageId(),
            'provider_account_id' => $subscription->providerAccountId(),
            'currency_code' => $subscription->currencyCode(),
            'amount_minor' => $subscription->amountMinor(),
            'payment_method' => $subscription->paymentMethod()?->value,
            'subscription_interval' => $subscription->interval()->value,
            'status' => $subscription->status()->value,
            'trial_ends_at' => $subscription->trialEndsAt()?->format('Y-m-d H:i:s'),
            'current_period_start' => $subscription->currentPeriodStart()?->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->currentPeriodEnd()?->format('Y-m-d H:i:s'),
            'error_code' => $subscription->errorCode(),
            'error_message' => $subscription->errorMessage(),
        ];
    }
}
