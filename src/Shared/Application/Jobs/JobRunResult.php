<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Jobs;

/**
 * The outcome of one {@see JobHandler::handle()} call. `summary` is
 * whatever counts/details are useful on the admin Jobs screen (e.g.
 * `['scanned' => 40, 'processed' => 3]`) — stored as `jobs.last_result`.
 */
final readonly class JobRunResult
{
    /**
     * @param array<string, scalar> $summary
     */
    private function __construct(
        public bool $success,
        public array $summary,
        public ?string $error,
    ) {
    }

    /**
     * @param array<string, scalar> $summary
     */
    public static function success(array $summary = []): self
    {
        return new self(true, $summary, null);
    }

    /**
     * @param array<string, scalar> $summary
     */
    public static function failure(string $error, array $summary = []): self
    {
        return new self(false, $summary, $error);
    }
}
