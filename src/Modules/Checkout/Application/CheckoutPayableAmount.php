<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application;

/**
 * The amount actually payable for a checkout attempt: the frozen pricing
 * snapshot's amount, overridden by the voucher redemption's payable amount
 * when one was reserved. Shared by every consumer that needs this figure
 * (`Payments\Application\CreatePayment\CreatePaymentHandler`,
 * `CreateProviderCheckoutHandler`) so the "voucher wins when present" rule
 * lives in exactly one place.
 */
final readonly class CheckoutPayableAmount
{
    public function __construct(
        public int $amountMinor,
        public string $currencyCode,
    ) {
    }
}
