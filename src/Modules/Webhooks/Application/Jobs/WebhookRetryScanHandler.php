<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Application\Jobs;

use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
use Gomrok\Modules\Webhooks\Domain\WebhookEventRepository;
use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;

/**
 * The `jobs`-table version of {@see \Gomrok\Jobs\RetryPendingWebhookEvents}
 * (Phase 25), migrated onto the unified queue (Phase 29 Q1). Reuses
 * {@see ProcessWebhookEventHandler} unchanged, same as the original — retrying
 * is provably identical to the first attempt, just later. The original
 * standalone job/`bin/` script stays operational until `bin/Worker.php`
 * exists (Q5, still pending) so background processing isn't lost in the
 * meantime; retire it once the new worker is proven.
 */
final readonly class WebhookRetryScanHandler implements JobHandler
{
    private const BATCH_SIZE = 100;
    private const RECURRENCE_MINUTES = 5;

    public function __construct(
        private WebhookEventRepository $events,
        private ProcessWebhookEventHandler $processor,
    ) {
    }

    public function type(): string
    {
        return 'webhook_retry_scan';
    }

    public function recurrenceIntervalMinutes(): int
    {
        return self::RECURRENCE_MINUTES;
    }

    public function handle(Job $job): JobRunResult
    {
        $summary = ['attempted' => 0, 'processed' => 0, 'retry_pending' => 0, 'failed' => 0];

        foreach ($this->events->findRetryable(self::BATCH_SIZE) as $event) {
            ++$summary['attempted'];
            $result = $this->processor->process($event);

            if (isset($summary[$result->outcome])) {
                ++$summary[$result->outcome];
            }
        }

        return JobRunResult::success($summary);
    }
}
