<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Infrastructure\SecretRedactor;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MySQL-backed {@see ErrorLogWriter}. Its own failures are swallowed and
 * reported to the application logger instead — persisting an error must never
 * throw over the top of the error it is recording.
 */
final readonly class PdoErrorLogWriter implements ErrorLogWriter
{
    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function log(ErrorLogEntry $entry): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO error_logs
                    (level, source, message, exception_class, code, client_id,
                     correlation_id, context, stack_trace, created_at)
                 VALUES
                    (:level, :source, :message, :exception_class, :code, :client_id,
                     :correlation_id, :context, :stack_trace, :created_at)',
            );

            $statement->execute([
                'level' => $entry->level->value,
                'source' => $entry->source,
                'message' => $entry->message,
                'exception_class' => $entry->exceptionClass,
                'code' => $entry->code,
                'client_id' => $entry->clientId,
                'correlation_id' => $entry->correlationId,
                'context' => $entry->context === null
                    ? null
                    : json_encode(SecretRedactor::redact($entry->context), JSON_THROW_ON_ERROR),
                'stack_trace' => $entry->stackTrace,
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('error_log.persist_failed', [
                'reason' => $e->getMessage(),
                'original_message' => $entry->message,
                'original_source' => $entry->source,
            ]);
        }
    }
}
