<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Dashboard;

final readonly class HomeDashboardResult
{
    /**
     * @param list<int>                $sparkline      24 hourly successful-payment counts for today
     * @param list<HomeOverviewStat>   $overviewStats
     * @param list<RecentPaymentRow>   $recentPayments
     */
    public function __construct(
        public int $successfulPaymentsToday,
        public int $failedPaymentsToday,
        public int $activeSubscriptions,
        public array $sparkline,
        public string $period,
        public array $overviewStats,
        public array $recentPayments,
    ) {
    }
}
