<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application;

use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\ResolvedProviderCapabilities;

/**
 * Everything a post-creation subscription action (cancel, Phase 26) needs to
 * call the right provider account about the right subscription — mirrors
 * {@see \Gomrok\Modules\Payments\Application\PaymentActionContext}. See
 * {@see ResolveSubscriptionActionContext}.
 */
final readonly class SubscriptionActionContext
{
    public function __construct(
        public int $providerAccountId,
        public PaymentProviderPort $adapter,
        public ResolvedProviderCapabilities $capabilities,
        public string $providerReference,
    ) {
    }
}
