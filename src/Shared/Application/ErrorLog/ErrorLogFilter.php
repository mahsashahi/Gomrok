<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\ErrorLog;

/**
 * Query parameters for {@see ErrorLogDirectory::search()} /
 * {@see ErrorLogDirectory::countMatching()}. Every field is optional — an
 * unset field is unrestricted. `resolved`: `true` = only resolved rows,
 * `false` = only unresolved, `null` = both.
 */
final readonly class ErrorLogFilter
{
    public function __construct(
        public ?string $level = null,
        public ?string $source = null,
        public ?int $clientId = null,
        public ?bool $resolved = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
