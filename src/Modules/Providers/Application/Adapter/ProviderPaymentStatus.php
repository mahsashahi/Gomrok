<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

use Gomrok\Modules\Payments\Domain\PaymentStatus;

/**
 * The result of {@see PaymentProviderPort::getPaymentStatus()} /
 * {@see SupportsManualPolling::pollPaymentStatus()}. `rawStatus` is always
 * preserved alongside `mappedStatus` — CLAUDE.md's "unknown provider statuses
 * must be stored safely" rule, honoured even when the mapping is confident.
 */
final readonly class ProviderPaymentStatus
{
    public function __construct(
        public string $providerReference,
        public string $rawStatus,
        public PaymentStatus $mappedStatus,
        public ?string $paymentIntentReference = null,
    ) {
    }
}
