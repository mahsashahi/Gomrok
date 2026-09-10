<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Audit;

/**
 * Who performed an audited action.
 */
enum AuditActor: string
{
    case AdminUser = 'admin_user';
    case Client = 'client';
    case System = 'system';
}
