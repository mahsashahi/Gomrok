<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * The dimension context a {@see PriceRuleResolver} matches rules against. The
 * pricing group + currency come from the Phase 13 resolution; the rest from the
 * caller's request.
 */
final readonly class PriceRuleContext
{
    public function __construct(
        public int $pricingGroupId,
        public string $country,
        public string $currency,
        public ?int $providerAccountId = null,
        public ?PaymentMethod $paymentMethod = null,
        public ?PurchaseType $purchaseType = null,
        public ?SubscriptionInterval $subscriptionInterval = null,
    ) {
    }
}
