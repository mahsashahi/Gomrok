<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\SetProviderGroupAccounts;

/**
 * Replace a provider group's ordered provider-account list. `entries` is the
 * full new list; anything not listed is removed from the group.
 */
final readonly class SetProviderGroupAccountsCommand
{
    /**
     * @param list<ProviderGroupAccountInput> $entries
     */
    public function __construct(
        public int $groupId,
        public array $entries,
        public ?int $actorId = null,
    ) {
    }
}
