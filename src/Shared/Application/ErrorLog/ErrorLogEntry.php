<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\ErrorLog;

use Throwable;

/**
 * One row for `error_logs`. Either build it directly or from a caught throwable
 * via {@see self::fromThrowable()}.
 *
 * `source` names the subsystem that caught the failure — `http`, `webhook`,
 * `provider`, `notification`, `job`. `context` carries the structured
 * operational detail an admin needs (payment id, provider, provider transaction
 * id, …); the writer redacts secret-bearing keys.
 */
final readonly class ErrorLogEntry
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public ErrorLogLevel $level,
        public string $source,
        public string $message,
        public ?string $exceptionClass = null,
        public ?string $code = null,
        public ?int $clientId = null,
        public ?string $correlationId = null,
        public ?array $context = null,
        public ?string $stackTrace = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public static function fromThrowable(
        Throwable $exception,
        string $source,
        ErrorLogLevel $level = ErrorLogLevel::Error,
        ?int $clientId = null,
        ?string $correlationId = null,
        ?array $context = null,
    ): self {
        $code = $exception->getCode();

        return new self(
            $level,
            $source,
            $exception->getMessage(),
            $exception::class,
            $code === 0 ? null : (string) $code,
            $clientId,
            $correlationId,
            $context,
            $exception->getTraceAsString(),
        );
    }
}
