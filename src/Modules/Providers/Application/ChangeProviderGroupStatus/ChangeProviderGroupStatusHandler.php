<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\ChangeProviderGroupStatus;

use Gomrok\Modules\Providers\Application\ProviderGroupAuditSnapshot;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Modules\Providers\Domain\ProviderGroupStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Disable / enable a provider group (soft, reversible). A disabled group is
 * skipped by the router — its countries fall through to the client's default
 * group. Idempotent.
 */
final readonly class ChangeProviderGroupStatusHandler
{
    public function __construct(
        private ProviderGroupRepository $groups,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function disable(int $groupId, ?int $actorId = null): Result
    {
        return $this->apply($groupId, ProviderGroupStatus::Disabled, $actorId);
    }

    public function enable(int $groupId, ?int $actorId = null): Result
    {
        return $this->apply($groupId, ProviderGroupStatus::Active, $actorId);
    }

    private function apply(int $groupId, ProviderGroupStatus $target, ?int $actorId): Result
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return Result::err(DomainError::notFound('provider_group.not_found', "Provider group {$groupId} was not found."));
        }

        if ($group->status() === $target) {
            return Result::ok(null);
        }

        $before = ProviderGroupAuditSnapshot::of($group);
        $now = $this->clock->now();
        $action = $target === ProviderGroupStatus::Disabled ? 'provider_group.disabled' : 'provider_group.enabled';

        if ($target === ProviderGroupStatus::Disabled) {
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

            $this->audit->record(
                $entry
                    ->withTarget('provider_group', $groupId)
                    ->withChange($before, ProviderGroupAuditSnapshot::of($group)),
            );
        });

        return Result::ok(null);
    }
}
