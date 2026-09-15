<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Application\IngestWebhookEvent;

final readonly class IngestWebhookEventCommand
{
    /**
     * @param array<string, string> $headers raw request headers, keyed by name as received
     */
    public function __construct(
        public string $token,
        public string $rawBody,
        public array $headers,
    ) {
    }
}
