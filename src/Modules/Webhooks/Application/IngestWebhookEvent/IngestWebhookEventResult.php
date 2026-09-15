<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Application\IngestWebhookEvent;

final readonly class IngestWebhookEventResult
{
    /**
     * @param 'duplicate'|'processed'|'retry_pending'|'failed' $outcome
     */
    public function __construct(
        public int $webhookEventId,
        public string $outcome,
    ) {
    }
}
