<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Sales;

final readonly class SalesPaymentRow
{
    /**
     * @param list<SalesTimelineEntry> $timeline
     */
    public function __construct(
        public int $paymentId,
        public string $amount,
        public string $customer,
        public string $provider,
        public ?string $method,
        public string $purchaseType,
        public string $status,
        public string $createdAt,
        public array $timeline,
    ) {
    }
}
