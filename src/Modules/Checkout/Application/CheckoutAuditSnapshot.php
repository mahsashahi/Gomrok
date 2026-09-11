<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application;

use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;

/**
 * `before` / `after` payloads for `checkout_attempt.*` audit rows.
 */
final class CheckoutAuditSnapshot
{
    /**
     * @return array<string, scalar|null>
     */
    public static function attempt(CheckoutAttempt $attempt): array
    {
        return [
            'id' => $attempt->id(),
            'client_id' => $attempt->clientId(),
            'attempt_reference' => $attempt->attemptReference(),
            'package_id' => $attempt->packageId(),
            'country' => $attempt->country(),
            'currency_code' => $attempt->currencyCode(),
            'purchase_type' => $attempt->purchaseType()?->value,
            'payment_method' => $attempt->paymentMethod()?->value,
            'subscription_interval' => $attempt->subscriptionInterval()?->value,
            'status' => $attempt->status()->value,
            'error_code' => $attempt->errorCode(),
            'error_message' => $attempt->errorMessage(),
        ];
    }
}
