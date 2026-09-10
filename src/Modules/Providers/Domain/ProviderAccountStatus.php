<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Provider account lifecycle — soft, reversible (like `clients`). A `disabled`
 * account is skipped by the router.
 */
enum ProviderAccountStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
