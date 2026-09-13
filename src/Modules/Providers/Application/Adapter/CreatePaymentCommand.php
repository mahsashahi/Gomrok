<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

use Gomrok\Modules\Providers\Domain\PaymentMethod;

/**
 * Input to {@see PaymentProviderPort::createPayment()} and
 * {@see SupportsAuthCapture::authorizePayment()} — a hosted-checkout request
 * (Phase 21 Q1). Amounts are raw minor units + an ISO currency code (Phase 21
 * Q3), matching {@see \Gomrok\Modules\Payments\Domain\Payment}, not `Money`.
 *
 * `paymentMethod` (Phase 22 Q3) is the specific method Gomrok's routing already
 * resolved, so a provider whose hosted checkout can be locked to one method
 * (Mollie) doesn't let the customer pick a different, unauthorized one.
 * `null` means "no restriction" — Stripe and other adapters that don't support
 * per-request method restriction simply ignore it.
 */
final readonly class CreatePaymentCommand
{
    /**
     * @param array<string, string> $metadata opaque key/value pairs echoed back on the provider's webhooks (e.g. `payment_id`, `checkout_attempt_id`)
     */
    public function __construct(
        public string $attemptReference,
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $customerEmail = null,
        public array $metadata = [],
        public ?PaymentMethod $paymentMethod = null,
    ) {
    }
}
