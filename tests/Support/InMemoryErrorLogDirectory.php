<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\ErrorLog\ErrorLogDirectory;
use Gomrok\Shared\Application\ErrorLog\ErrorLogFilter;
use Gomrok\Shared\Application\ErrorLog\ErrorLogRecord;

final class InMemoryErrorLogDirectory implements ErrorLogDirectory
{
    /** @var list<ErrorLogRecord> */
    private array $records = [];

    public function add(ErrorLogRecord $record): self
    {
        $this->records[] = $record;

        return $this;
    }

    public function find(int $id): ?ErrorLogRecord
    {
        foreach ($this->records as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    public function search(ErrorLogFilter $filter): array
    {
        $matching = array_values(array_filter($this->records, fn (ErrorLogRecord $r): bool => $this->matches($r, $filter)));
        usort($matching, static fn (ErrorLogRecord $a, ErrorLogRecord $b): int => $b->id <=> $a->id);

        return \array_slice($matching, $filter->offset, $filter->limit);
    }

    public function countMatching(ErrorLogFilter $filter): int
    {
        return \count(array_filter($this->records, fn (ErrorLogRecord $r): bool => $this->matches($r, $filter)));
    }

    public function distinctSources(): array
    {
        $sources = array_values(array_unique(array_map(static fn (ErrorLogRecord $r): string => $r->source, $this->records)));
        sort($sources);

        return $sources;
    }

    /**
     * Mirrors {@see \Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogDirectory}'s
     * update-in-place behavior for the resolver's writes, since this double is
     * also what `SetErrorLogResolutionHandler`'s tests read back state from.
     */
    public function replace(ErrorLogRecord $record): void
    {
        foreach ($this->records as $index => $existing) {
            if ($existing->id === $record->id) {
                $this->records[$index] = $record;

                return;
            }
        }

        $this->records[] = $record;
    }

    private function matches(ErrorLogRecord $record, ErrorLogFilter $filter): bool
    {
        return ($filter->level === null || $record->level === $filter->level)
            && ($filter->source === null || $record->source === $filter->source)
            && ($filter->clientId === null || $record->clientId === $filter->clientId)
            && ($filter->resolved === null || $record->isResolved() === $filter->resolved);
    }
}
