<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

/**
 * One {@see \Gomrok\Modules\Providers\Domain\ProviderGroup} an account is
 * linked into, for the account detail's "used in these routing groups"
 * readout.
 */
final readonly class AccountGroupMembership
{
    public function __construct(
        public string $groupSlug,
        public string $groupName,
        public int $priority,
        public bool $isEnabled,
    ) {
    }
}
