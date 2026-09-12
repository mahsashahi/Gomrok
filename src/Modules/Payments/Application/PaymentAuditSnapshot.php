<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application;

use Gomrok\Modules\Payments\Domain\Payment;

/**
 * `before` / `after` payloads for `payment.*` audit rows.
 */
final class PaymentAuditSnapshot
{
    /**
     * @return array<string, scalar|null>
     */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id(),
            'client_id' => $payment->clientId(),
            'checkout_attempt_id' => $payment->checkoutAttemptId(),
            'package_id' => $payment->packageId(),
            'country' => $payment->country(),
            'currency_code' => $payment->currencyCode(),
            'amount_minor' => $payment->amountMinor(),
            'purchase_type' => $payment->purchaseType()->value,
            'payment_method' => $payment->paymentMethod()?->value,
            'subscription_interval' => $payment->subscriptionInterval()?->value,
            'status' => $payment->status()->value,
            'error_code' => $payment->errorCode(),
            'error_message' => $payment->errorMessage(),
        ];
    }
}
