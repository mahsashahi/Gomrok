<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Domain\PriceRule;
use Gomrok\Modules\Pricing\Domain\PriceRuleRepository;

/**
 * Picks the winning {@see PriceRule} for a `(client, package)` in a
 * {@see PriceRuleContext} (Phase 14). A rule matches when every non-null
 * dimension equals the request. Precedence (Phase 14 Q3):
 *
 *   1. matched-dimension count, descending;
 *   2. the fixed dimension priority order ({@see PriceRule::DIMENSIONS}) —
 *      the rule that pins the earlier dimension wins;
 *   3. highest `id` (most recently written).
 *
 * Returns null when no rule matches (→ the Phase 13 base price stands).
 */
final readonly class PriceRuleResolver
{
    public function __construct(private PriceRuleRepository $rules)
    {
    }

    public function resolve(int $clientId, int $packageId, PriceRuleContext $context): ?PriceRule
    {
        $matches = [];
        foreach ($this->rules->forClientPackage($clientId, $packageId) as $rule) {
            if ($rule->matches(
                $context->pricingGroupId,
                $context->country,
                $context->providerAccountId,
                $context->paymentMethod,
                $context->purchaseType,
                $context->subscriptionInterval,
                $context->currency,
            )) {
                $matches[] = $rule;
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static function (PriceRule $a, PriceRule $b): int {
            return [$b->specificity(), $b->tieBreak(), $b->id() ?? 0]
                <=> [$a->specificity(), $a->tieBreak(), $a->id() ?? 0];
        });

        return $matches[0];
    }
}
