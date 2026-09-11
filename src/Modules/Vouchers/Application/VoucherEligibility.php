<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * The result of {@see VoucherEligibilityEvaluator::evaluate} (Phase 16 Q4):
 * every unmet condition, not just the first one.
 */
final readonly class VoucherEligibility
{
    /**
     * @param list<string> $reasons
     */
    private function __construct(
        public bool $eligible,
        public array $reasons,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $reasons
     */
    public static function failing(array $reasons): self
    {
        return new self($reasons === [], $reasons);
    }
}
