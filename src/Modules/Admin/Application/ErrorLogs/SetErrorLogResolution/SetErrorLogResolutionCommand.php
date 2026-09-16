<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ErrorLogs\SetErrorLogResolution;

/**
 * The Error Logs screen's "Mark resolved" / "Reopen" action — one command
 * covering both directions, mirroring
 * {@see \Gomrok\Modules\Admin\Application\SetAdminUserStatus\SetAdminUserStatusCommand}'s
 * shape for the same "single boolean toggle" kind of problem.
 */
final readonly class SetErrorLogResolutionCommand
{
    public function __construct(
        public int $errorLogId,
        public bool $resolved,
        public ?int $actorId = null,
    ) {
    }
}
