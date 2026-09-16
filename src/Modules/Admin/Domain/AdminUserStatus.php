<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

/**
 * CLAUDE.md: "add account status such as active, disabled, or locked."
 * `Locked` is distinct from `Disabled` — `Locked` is set automatically after
 * too many failed login attempts (self-inflicted, time-boxed in a future
 * unlock flow); `Disabled` is a deliberate admin action with no automatic
 * path back.
 */
enum AdminUserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Locked = 'locked';
}
