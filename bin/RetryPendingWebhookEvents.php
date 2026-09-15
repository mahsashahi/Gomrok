<?php

declare(strict_types=1);

/**
 * CLI entrypoint for {@see \Gomrok\Jobs\RetryPendingWebhookEvents}.
 *
 * Run ad hoc (`composer webhook:retry-pending`) or from cron until the job
 * runner exists (Phase 29):
 *
 *     * /5 * * * *  php /path/to/gomrok/bin/RetryPendingWebhookEvents.php
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Jobs\RetryPendingWebhookEvents;

require dirname(__DIR__) . '/vendor/autoload.php';

$job = ContainerFactory::create()->get(RetryPendingWebhookEvents::class);
assert($job instanceof RetryPendingWebhookEvents);

$summary = $job();

fwrite(STDOUT, sprintf(
    "webhook retry: attempted=%d processed=%d retry_pending=%d failed=%d\n",
    $summary['attempted'],
    $summary['processed'],
    $summary['retry_pending'],
    $summary['failed'],
));

exit(0);
