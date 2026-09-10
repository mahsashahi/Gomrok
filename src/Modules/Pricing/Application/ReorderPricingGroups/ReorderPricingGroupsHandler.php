<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\ReorderPricingGroups;

use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

final readonly class ReorderPricingGroupsHandler
{
    public function __construct(
        private PricingGroupRepository $groups,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ReorderPricingGroupsCommand $command): Result
    {
        $all = $this->groups->forClient($command->clientId);

        /** @var array<string, PricingGroup> $nonDefaultBySlug */
        $nonDefaultBySlug = [];
        foreach ($all as $group) {
            if (!$group->isDefault()) {
                $nonDefaultBySlug[$group->slug()->value] = $group;
            }
        }

        $ordered = array_values(array_unique($command->orderedSlugs));
        if (\count($ordered) !== \count($nonDefaultBySlug)) {
            return Result::err(DomainError::validation(
                'pricing_group.reorder_incomplete',
                'The ordered list must contain exactly the client\'s non-default pricing group slugs.',
                ['expected' => \count($nonDefaultBySlug), 'given' => \count($ordered)],
            ));
        }
        foreach ($ordered as $slug) {
            if (!isset($nonDefaultBySlug[$slug])) {
                return Result::err(DomainError::validation('pricing_group.reorder_unknown', "Unknown or default pricing group '{$slug}' in the order.", ['slug' => $slug]));
            }
        }

        $now = $this->clock->now();
        $changed = [];
        foreach ($ordered as $i => $slug) {
            $group = $nonDefaultBySlug[$slug];
            $priority = $i + 1;
            if ($group->priority() !== $priority) {
                $before = PricingAuditSnapshot::group($group);
                $group->reprioritise($priority, $now);
                $changed[] = [$group, $before];
            }
        }

        if ($changed === []) {
            return Result::ok(null);
        }

        $clientId = $command->clientId;
        $this->transactions->run(function () use ($changed, $clientId, $command): void {
            foreach ($changed as [$group, $before]) {
                $this->groups->save($group);
                $groupId = $group->id();
                \assert($groupId !== null);

                $entry = $command->actorId !== null
                    ? AuditEntry::forAdminUser($command->actorId, $clientId, 'pricing_group.reordered')
                    : AuditEntry::forSystem('pricing_group.reordered', $clientId);

                $this->audit->record($entry->withTarget('pricing_group', $groupId)->withChange($before, PricingAuditSnapshot::group($group)));
            }
        });

        return Result::ok(null);
    }
}
