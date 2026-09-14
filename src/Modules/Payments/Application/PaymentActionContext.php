<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application;

use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\ResolvedProviderCapabilities;

/**
 * Everything a post-creation payment action (cancel/refund/capture, Phase 24)
 * needs to call the right provider account about the right payment: the
 * adapter instance, its resolved capability set for this client/country
 * combination, and the specific provider reference to act on — see
 * {@see ResolvePaymentActionContext}.
 */
final readonly class PaymentActionContext
{
    public function __construct(
        public int $providerAccountId,
        public PaymentProviderPort $adapter,
        public ResolvedProviderCapabilities $capabilities,
        public string $providerReference,
    ) {
    }
}
