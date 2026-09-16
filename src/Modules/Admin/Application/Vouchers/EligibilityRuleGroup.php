<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers;

/**
 * Eligibility rules folded by dimension for display. `.claude/Voucher.md` §5:
 * several rules for the same dimension are OR'd, across dimensions AND'd, and
 * a dimension with no rules is unrestricted — so an absent group means "any".
 */
final readonly class EligibilityRuleGroup
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public string $dimension,
        public string $dimensionLabel,
        public array $values,
    ) {
    }
}
