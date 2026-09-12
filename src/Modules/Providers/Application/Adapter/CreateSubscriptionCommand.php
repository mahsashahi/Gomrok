<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;

/**
 * Input to {@see SupportsSubscriptions::createSubscription()} — a hosted
 * checkout in subscription mode. The price is supplied ad hoc (minor units +
 * currency + interval) rather than referencing a pre-created provider-side
 * price object, so Gomrok's own pricing resolution stays the single source of
 * truth for the amount.
 */
final readonly class CreateSubscriptionCommand
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $attemptReference,
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public SubscriptionInterval $interval,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $customerEmail = null,
        public array $metadata = [],
    ) {
    }
}
