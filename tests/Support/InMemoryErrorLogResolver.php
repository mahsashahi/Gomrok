<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Shared\Application\ErrorLog\ErrorLogRecord;
use Gomrok\Shared\Application\ErrorLog\ErrorLogResolver;

/**
 * Writes through to the same {@see InMemoryErrorLogDirectory} instance the
 * test also reads from — mirrors the real pair, where
 * {@see \Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogResolver} and
 * {@see \Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogDirectory} are
 * two ports over the same `error_logs` table rather than two independent
 * stores.
 */
final class InMemoryErrorLogResolver implements ErrorLogResolver
{
    public function __construct(private InMemoryErrorLogDirectory $directory)
    {
    }

    public function markResolved(int $id, ?int $adminUserId, DateTimeImmutable $now): bool
    {
        $record = $this->directory->find($id);
        if ($record === null) {
            return false;
        }

        $this->directory->replace(new ErrorLogRecord(
            $record->id,
            $record->level,
            $record->source,
            $record->message,
            $record->exceptionClass,
            $record->code,
            $record->clientId,
            $record->correlationId,
            $record->context,
            $record->stackTrace,
            $record->createdAt,
            $now->format('Y-m-d H:i:s'),
            $adminUserId,
        ));

        return true;
    }

    public function markUnresolved(int $id): bool
    {
        $record = $this->directory->find($id);
        if ($record === null) {
            return false;
        }

        $this->directory->replace(new ErrorLogRecord(
            $record->id,
            $record->level,
            $record->source,
            $record->message,
            $record->exceptionClass,
            $record->code,
            $record->clientId,
            $record->correlationId,
            $record->context,
            $record->stackTrace,
            $record->createdAt,
            null,
            null,
        ));

        return true;
    }
}
