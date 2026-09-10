<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;

final class RecordingAuditLogWriter implements AuditLogWriter
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn (AuditEntry $e): string => $e->action, $this->entries);
    }
}
