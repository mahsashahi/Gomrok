<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers\ManageProviderGroupAccountsForAdmin;

use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\ProviderGroupAccountInput;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsCommand;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsHandler;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * The Providers screen's "By-groups" tab account-chain management (Phase 27)
 * — add / remove / toggle / drag-reorder, all funnelled through
 * {@see SetProviderGroupAccountsHandler}'s full-replace contract. Each
 * operation reads the group's current ordered entries first so it only
 * changes the one thing it means to, mirroring
 * {@see \Gomrok\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages\ReorderPricingGroupPackagesHandler}'s
 * read-before-write shape — simpler here since a provider-group entry carries
 * no price data to accidentally clobber, only `priority` and `isEnabled`.
 */
final readonly class ManageProviderGroupAccountsForAdminHandler
{
    public function __construct(
        private ProviderGroupRepository $groups,
        private SetProviderGroupAccountsHandler $setAccounts,
    ) {
    }

    public function add(int $groupId, int $providerAccountId, ?int $actorId = null): Result
    {
        $entries = $this->currentEntries($groupId);
        if ($entries === null) {
            return Result::err(DomainError::notFound('provider_group.not_found', "Provider group {$groupId} was not found."));
        }

        foreach ($entries as $entry) {
            if ($entry->providerAccountId === $providerAccountId) {
                return Result::ok(null);
            }
        }
        $entries[] = new ProviderGroupAccountInput($providerAccountId, \count($entries), true);

        return $this->apply($groupId, $entries, $actorId);
    }

    public function remove(int $groupId, int $providerAccountId, ?int $actorId = null): Result
    {
        $entries = $this->currentEntries($groupId);
        if ($entries === null) {
            return Result::err(DomainError::notFound('provider_group.not_found', "Provider group {$groupId} was not found."));
        }

        $remaining = array_values(array_filter($entries, static fn (ProviderGroupAccountInput $e): bool => $e->providerAccountId !== $providerAccountId));

        return $this->apply($groupId, $remaining, $actorId);
    }

    public function toggle(int $groupId, int $providerAccountId, ?int $actorId = null): Result
    {
        $entries = $this->currentEntries($groupId);
        if ($entries === null) {
            return Result::err(DomainError::notFound('provider_group.not_found', "Provider group {$groupId} was not found."));
        }

        $updated = array_map(
            static fn (ProviderGroupAccountInput $e): ProviderGroupAccountInput => $e->providerAccountId === $providerAccountId
                ? new ProviderGroupAccountInput($e->providerAccountId, $e->priority, !$e->isEnabled)
                : $e,
            $entries,
        );

        return $this->apply($groupId, $updated, $actorId);
    }

    /**
     * @param list<int> $providerAccountIdsInOrder
     */
    public function reorder(int $groupId, array $providerAccountIdsInOrder, ?int $actorId = null): Result
    {
        $entries = $this->currentEntries($groupId);
        if ($entries === null) {
            return Result::err(DomainError::notFound('provider_group.not_found', "Provider group {$groupId} was not found."));
        }
        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry->providerAccountId] = $entry;
        }

        $reordered = [];
        foreach ($providerAccountIdsInOrder as $index => $accountId) {
            $existing = $byId[$accountId] ?? null;
            $reordered[] = new ProviderGroupAccountInput($accountId, $index, $existing !== null ? $existing->isEnabled : true);
        }

        return $this->apply($groupId, $reordered, $actorId);
    }

    /**
     * @return list<ProviderGroupAccountInput>|null
     */
    private function currentEntries(int $groupId): ?array
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return null;
        }

        return array_map(
            static fn (ProviderGroupAccount $a): ProviderGroupAccountInput => new ProviderGroupAccountInput($a->providerAccountId(), $a->priority(), $a->isEnabled()),
            $group->accounts(),
        );
    }

    /**
     * @param list<ProviderGroupAccountInput> $entries
     */
    private function apply(int $groupId, array $entries, ?int $actorId): Result
    {
        return $this->setAccounts->handle(new SetProviderGroupAccountsCommand($groupId, $entries, $actorId));
    }
}
