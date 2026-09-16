<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\Audit\AuditLogDirectory;
use Gomrok\Shared\Application\Audit\AuditLogEntry;
use Gomrok\Shared\Application\Audit\AuditLogFilter;

final class InMemoryAuditLogDirectory implements AuditLogDirectory
{
    /** @var list<AuditLogEntry> */
    private array $entries = [];

    public function add(AuditLogEntry $entry): self
    {
        $this->entries[] = $entry;

        return $this;
    }

    public function search(AuditLogFilter $filter): array
    {
        $matching = array_values(array_filter($this->entries, fn (AuditLogEntry $e): bool => $this->matches($e, $filter)));
        usort($matching, static fn (AuditLogEntry $a, AuditLogEntry $b): int => $b->id <=> $a->id);

        return \array_slice($matching, $filter->offset, $filter->limit);
    }

    public function countMatching(AuditLogFilter $filter): int
    {
        return \count(array_filter($this->entries, fn (AuditLogEntry $e): bool => $this->matches($e, $filter)));
    }

    public function distinctActions(): array
    {
        $actions = array_values(array_unique(array_map(static fn (AuditLogEntry $e): string => $e->action, $this->entries)));
        sort($actions);

        return $actions;
    }

    private function matches(AuditLogEntry $entry, AuditLogFilter $filter): bool
    {
        return ($filter->actorType === null || $entry->actorType === $filter->actorType)
            && ($filter->clientId === null || $entry->clientId === $filter->clientId)
            && ($filter->action === null || $entry->action === $filter->action)
            && ($filter->targetType === null || $entry->targetType === $filter->targetType)
            && ($filter->targetId === null || $entry->targetId === $filter->targetId);
    }
}
