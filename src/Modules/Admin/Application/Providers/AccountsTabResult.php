<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

final readonly class AccountsTabResult
{
    /**
     * @param list<AccountListItem> $accounts
     */
    public function __construct(
        public array $accounts,
        public ?AccountDetail $selected,
    ) {
    }
}
