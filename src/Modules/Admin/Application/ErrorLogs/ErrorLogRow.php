<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ErrorLogs;

final readonly class ErrorLogRow
{
    public function __construct(
        public int $id,
        public string $level,
        public string $source,
        public string $message,
        public ?string $exceptionClass,
        public ?string $code,
        public ?string $clientLabel,
        public ?string $correlationId,
        public ?string $contextJson,
        public ?string $stackTrace,
        public string $createdLabel,
        public bool $isResolved,
        public ?string $resolvedLabel,
        public ?string $resolvedByLabel,
    ) {
    }

    public function hasDetail(): bool
    {
        return $this->contextJson !== null || $this->stackTrace !== null;
    }
}
