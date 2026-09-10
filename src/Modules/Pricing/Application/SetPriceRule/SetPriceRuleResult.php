<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceRule;

final readonly class SetPriceRuleResult
{
    public function __construct(
        public int $ruleId,
        public bool $created,
    ) {
    }
}
