<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\SetProviderGroupAccounts;

use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderGroupAuditSnapshot;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Sets the ordered provider-account list on a group. Every referenced account
 * must exist and belong to the group's client.
 */
final readonly class SetProviderGroupAccountsHandler
{
    public function __construct(
        private ProviderGroupRepository $groups,
        private ProviderAccountDirectory $accounts,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetProviderGroupAccountsCommand $command): Result
    {
        $group = $this->groups->findById($command->groupId);
        if ($group === null) {
            return Result::err(DomainError::notFound('provider_group.not_found', "Provider group {$command->groupId} was not found."));
        }

        $ownedIds = [];
        foreach ($this->accounts->forClient($group->clientId()) as $summary) {
            $ownedIds[$summary->id] = true;
        }

        $seen = [];
        $entries = [];
        foreach ($command->entries as $input) {
            if (isset($seen[$input->providerAccountId])) {
                return Result::err(DomainError::validation(
                    'provider_group.duplicate_account',
                    "Provider account {$input->providerAccountId} is listed twice.",
                    ['provider_account_id' => $input->providerAccountId],
                ));
            }
            $seen[$input->providerAccountId] = true;

            if (!isset($ownedIds[$input->providerAccountId])) {
                return Result::err(DomainError::validation(
                    'provider_group.account_not_owned',
                    "Provider account {$input->providerAccountId} does not belong to this client.",
                    ['provider_account_id' => $input->providerAccountId],
                ));
            }

            $entries[] = ProviderGroupAccount::link($input->providerAccountId, $input->priority, $input->isEnabled);
        }

        $before = ProviderGroupAuditSnapshot::of($group);
        $group->setAccounts($entries, $this->clock->now());

        $clientId = $group->clientId();
        $this->transactions->run(function () use ($group, $before, $clientId, $command): void {
            $this->groups->save($group);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'provider_group.accounts_updated')
                : AuditEntry::forSystem('provider_group.accounts_updated', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('provider_group', $command->groupId)
                    ->withChange($before, ProviderGroupAuditSnapshot::of($group)),
            );
        });

        return Result::ok(null);
    }
}
