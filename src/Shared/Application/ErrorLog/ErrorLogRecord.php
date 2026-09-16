<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\ErrorLog;

/**
 * Read-only view of one persisted `error_logs` row (Phase 27's Error Logs
 * admin screen). Distinct from {@see ErrorLogEntry} — that DTO is what a
 * caller builds to *write* a new row; this is what comes back out. `context`
 * is already secret-redacted at write time by
 * {@see \Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogWriter} — this
 * DTO decodes the stored JSON, it does not redact again.
 */
final readonly class ErrorLogRecord
{
    /**
     * @param array<array-key, mixed>|null $context
     */
    public function __construct(
        public int $id,
        public string $level,
        public string $source,
        public string $message,
        public ?string $exceptionClass,
        public ?string $code,
        public ?int $clientId,
        public ?string $correlationId,
        public ?array $context,
        public ?string $stackTrace,
        public string $createdAt,
        public ?string $resolvedAt,
        public ?int $resolvedBy,
    ) {
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }
}
