<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Dashboard;

/**
 * One "Your overview" stat card: a value for the selected period plus its
 * percentage change against the immediately preceding period of equal
 * length. `deltaLabel` is `null` when there's no prior-period data to
 * compare against (nothing to divide by).
 */
final readonly class HomeOverviewStat
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $deltaLabel,
        public bool $deltaIsPositive,
    ) {
    }
}
