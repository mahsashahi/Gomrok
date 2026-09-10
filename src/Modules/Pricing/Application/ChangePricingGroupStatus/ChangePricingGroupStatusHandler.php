<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\ChangePricingGroupStatus;

use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Disable / enable a pricing group (soft, reversible). Idempotent. The default
 * group cannot be disabled — a client always needs a fallback.
 */
final readonly class ChangePricingGroupStatusHandler
{
    public function __construct(
        private PricingGroupRepository $groups,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function disable(int $groupId, ?int $actorId = null): Result
    {
        return $this->apply($groupId, PricingGroupStatus::Disabled, $actorId);
    }

    public function enable(int $groupId, ?int $actorId = null): Result
    {
        return $this->apply($groupId, PricingGroupStatus::Active, $actorId);
    }

    private function apply(int $groupId, PricingGroupStatus $target, ?int $actorId): Result
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return Result::err(DomainError::notFound('pricing_group.not_found', "Pricing group {$groupId} was not found."));
        }
        if ($target === PricingGroupStatus::Disabled && $group->isDefault()) {
            return Result::err(DomainError::validation('pricing_group.cannot_disable_default', 'The default pricing group cannot be disabled.'));
        }
        if ($group->status() === $target) {
            return Result::ok(null);
        }

        $before = PricingAuditSnapshot::group($group);
        $now = $this->clock->now();
        $action = $target === PricingGroupStatus::Disabled ? 'pricing_group.disabled' : 'pricing_group.enabled';

        if ($target === PricingGroupStatus::Disabled) {
            $group->disable($now);
        } else {
            $group->enable($now);
        }

        $clientId = $group->clientId();
        $this->transactions->run(function () use ($group, $before, $clientId, $groupId, $action, $actorId): void {
            $this->groups->save($group);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, $action)
                : AuditEntry::forSystem($action, $clientId);

            $this->audit->record($entry->withTarget('pricing_group', $groupId)->withChange($before, PricingAuditSnapshot::group($group)));
        });

        return Result::ok(null);
    }
}
