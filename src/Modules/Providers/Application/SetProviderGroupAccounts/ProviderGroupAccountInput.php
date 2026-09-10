<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\SetProviderGroupAccounts;

/**
 * One requested entry in a provider group's ordered account list.
 */
final readonly class ProviderGroupAccountInput
{
    public function __construct(
        public int $providerAccountId,
        public int $priority,
        public bool $isEnabled = true,
    ) {
    }
}
