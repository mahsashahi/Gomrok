<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Dashboard;

final readonly class RecentPaymentRow
{
    public function __construct(
        public string $amount,
        public string $customer,
        public string $provider,
        public string $status,
        public string $createdAt,
    ) {
    }
}
