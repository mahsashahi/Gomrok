<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Audit;

/**
 * Query parameters for {@see AuditLogDirectory::search()} /
 * {@see AuditLogDirectory::countMatching()}. Every field is optional — an
 * unset field is unrestricted. `action` is an exact match against one of
 * {@see AuditLogDirectory::distinctActions()}'s values, matching the screen's
 * filter dropdown rather than a free-text prefix search.
 */
final readonly class AuditLogFilter
{
    public function __construct(
        public ?string $actorType = null,
        public ?int $clientId = null,
        public ?string $action = null,
        public ?string $targetType = null,
        public ?int $targetId = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
