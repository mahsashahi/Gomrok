<?php

declare(strict_types=1);

/**
 * CLI entrypoint for {@see \Gomrok\Jobs\RetryPendingClientNotifications}.
 *
 * Run ad hoc (`composer notifications:retry-pending`) or from cron until the
 * real job runner exists (Phase 29). Every minute, matching Q4's shortest
 * (1-minute) backoff step:
 *
 *     * * * * *  php /path/to/gomrok/bin/RetryPendingClientNotifications.php
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Jobs\RetryPendingClientNotifications;

require dirname(__DIR__) . '/vendor/autoload.php';

$job = ContainerFactory::create()->get(RetryPendingClientNotifications::class);
assert($job instanceof RetryPendingClientNotifications);

$summary = $job();

fwrite(STDOUT, sprintf("client notification retry: attempted=%d\n", $summary['attempted']));

exit(0);
