<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;

final readonly class ActivateSubscriptionCommand
{
    public function __construct(
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public SubscriptionInterval $interval,
        public ?string $webhookUrl,
    ) {
    }
}
