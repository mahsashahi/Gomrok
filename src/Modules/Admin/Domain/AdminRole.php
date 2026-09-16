<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

/**
 * The two admin roles CLAUDE.md requires for now — "do not add more roles
 * unless explicitly requested later." What each role can do is code-defined
 * in {@see \Gomrok\Modules\Admin\Application\AdminPermissions}, not stored in
 * the database (Phase 27 DB design confirmation).
 */
enum AdminRole: string
{
    case Admin = 'admin';
    case SupportAgent = 'support_agent';
}
