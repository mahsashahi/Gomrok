<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;

final class RecordingErrorLogWriter implements ErrorLogWriter
{
    /** @var list<ErrorLogEntry> */
    public array $entries = [];

    public function log(ErrorLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
