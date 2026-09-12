<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * A verified, decoded provider webhook event.
 */
final readonly class ParsedWebhookEvent
{
    /**
     * @param array<array-key, mixed> $payload the full decoded event, for the Webhooks module (Phase 25) to store verbatim
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $providerReference,
        public string $rawStatus,
        public array $payload,
    ) {
    }
}
