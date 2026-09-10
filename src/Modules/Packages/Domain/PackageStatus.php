<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

/**
 * Package lifecycle — soft, reversible. `disabled` is the per-client hide
 * switch: a disabled package never appears in a resolved catalogue list.
 */
enum PackageStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
