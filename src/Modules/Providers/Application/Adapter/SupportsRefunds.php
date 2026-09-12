<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * Optional (Phase 1 Q5). Partial vs. full refund is not a separate method —
 * a `null` `$amountMinor` means "refund the full remaining amount"; a
 * provider that only supports full refunds (see {@see \Gomrok\Modules\Providers\Domain\Capability::Refund}
 * vs. {@see \Gomrok\Modules\Providers\Domain\Capability::PartialRefund}) is
 * expected to reject a partial amount by throwing.
 */
interface SupportsRefunds
{
    /**
     * @throws ProviderAdapterException
     */
    public function refundPayment(string $providerReference, ?int $amountMinor = null): ProviderRefundResult;
}
