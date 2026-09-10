<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\IssueApiKey;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;

final readonly class IssueApiKeyCommand
{
    public function __construct(
        public int $clientId,
        public ApiKeyPrefix $prefix = ApiKeyPrefix::Live,
        public ?string $label = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
    }
}
