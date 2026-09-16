<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Customers;

final readonly class CustomersListResult
{
    /**
     * @param list<CustomerRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $totalCount,
    ) {
    }
}
