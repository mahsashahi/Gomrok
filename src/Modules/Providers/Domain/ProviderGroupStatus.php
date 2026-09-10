<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Provider group lifecycle — soft, reversible. A `disabled` group is skipped by
 * the router (it falls through to the client's default group).
 */
enum ProviderGroupStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
