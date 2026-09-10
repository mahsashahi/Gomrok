<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\ReorderPricingGroups;

/**
 * Set the priority order of a client's non-default pricing groups. `orderedSlugs`
 * is the full list of the client's non-default group slugs, most-specific first;
 * they are assigned `priority` 1..n. The default group is always last regardless.
 */
final readonly class ReorderPricingGroupsCommand
{
    /**
     * @param list<string> $orderedSlugs
     */
    public function __construct(
        public int $clientId,
        public array $orderedSlugs,
        public ?int $actorId = null,
    ) {
    }
}
