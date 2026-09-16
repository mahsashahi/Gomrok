<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Sales;

final readonly class SalesListResult
{
    /**
     * @param list<SalesStatusTab>  $tabs
     * @param list<SalesPaymentRow> $rows
     */
    public function __construct(
        public array $tabs,
        public array $rows,
        public int $totalCount,
    ) {
    }
}
