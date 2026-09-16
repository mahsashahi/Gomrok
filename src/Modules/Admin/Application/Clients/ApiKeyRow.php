<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Clients;

/**
 * A stored API key as the admin panel is allowed to see it — `displayToken`
 * only, never the plaintext. `.claude/docs/database-design.md`: `secret_hash`
 * is a one-way SHA-256, so there is nothing to reveal after mint time; the
 * plaintext exists only in {@see \Gomrok\Modules\Clients\Application\CreateClient\CreateClientResult}
 * / {@see \Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyResult}
 * at the moment a key is created, never again.
 */
final readonly class ApiKeyRow
{
    public function __construct(
        public string $keyId,
        public string $displayToken,
        public string $mode,
        public ?string $label,
        public string $status,
        public bool $isActive,
        public string $createdLabel,
        public ?string $lastUsedLabel,
        public ?string $expiresLabel,
    ) {
    }
}
