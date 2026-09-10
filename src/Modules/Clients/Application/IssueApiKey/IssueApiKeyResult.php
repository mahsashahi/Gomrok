<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\IssueApiKey;

use Gomrok\Modules\Clients\Domain\Events\ApiKeyIssued;

/**
 * `plaintextToken` is available only here — surface it once, never store it.
 */
final readonly class IssueApiKeyResult
{
    public function __construct(
        public string $keyId,
        public string $plaintextToken,
        public ApiKeyIssued $event,
    ) {
    }
}
