<?php

declare(strict_types=1);

namespace Gomrok\Jobs;

use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
use Gomrok\Modules\Webhooks\Domain\WebhookEventRepository;
use Psr\Log\LoggerInterface;

/**
 * The cron-invokable retry for webhook events left `received` / `retry_pending`
 * (Phase 25 Q3, user-specified `webhook:retry-pending`). A plain invokable —
 * run it from `bin/RetryPendingWebhookEvents.php` on a schedule now, wire it
 * into the scheduler when the real job runner lands (Phase 29). Reuses
 * {@see ProcessWebhookEventHandler} unchanged — the exact same processor
 * inline ingestion calls — so retrying is provably identical to the first
 * attempt, just later.
 */
final readonly class RetryPendingWebhookEvents
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private WebhookEventRepository $events,
        private ProcessWebhookEventHandler $processor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{attempted: int, processed: int, retry_pending: int, failed: int}
     */
    public function __invoke(): array
    {
        $summary = ['attempted' => 0, 'processed' => 0, 'retry_pending' => 0, 'failed' => 0];

        foreach ($this->events->findRetryable(self::BATCH_SIZE) as $event) {
            ++$summary['attempted'];
            $result = $this->processor->process($event);

            if (isset($summary[$result->outcome])) {
                ++$summary[$result->outcome];
            }
        }

        $this->logger->info('job.retry_pending_webhook_events', $summary);

        return $summary;
    }
}
