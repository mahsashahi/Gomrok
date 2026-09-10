<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Logging;

use Gomrok\Shared\Infrastructure\CorrelationId;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds the current request's `correlation_id` plus static context (`service`,
 * `env`) to every log record's `extra`.
 */
final readonly class CorrelationIdProcessor implements ProcessorInterface
{
    /**
     * @param array<string, scalar> $staticContext
     */
    public function __construct(
        private CorrelationId $correlationId,
        private array $staticContext = [],
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = [...$record->extra, ...$this->staticContext];

        $correlationId = $this->correlationId->get();
        if ($correlationId !== '') {
            $extra['correlation_id'] = $correlationId;
        }

        return $record->with(extra: $extra);
    }
}
