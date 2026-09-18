<?php

declare(strict_types=1);

/**
 * Persistent daemon worker for the unified `jobs` queue (Phase 29 Q5,
 * revised — user-specified: a persistent daemon, not a cron-invoked batch
 * worker). Continuously polls/claims due jobs and processes them until told
 * to stop — entirely through the existing {@see RunDueJobsHandler}
 * abstraction, unchanged. This script is purely an invocation shape around
 * it, not a parallel execution path: it does not reimplement claiming,
 * retries, backoff, locking, or dead-lettering, all of which already live
 * in `RunDueJobsHandler`/`Job`/`PdoJobRepository` regardless of how the
 * worker is invoked. No business job handler is touched by this file.
 *
 * Run directly for local development:
 *
 *     composer jobs:worker
 *     # or
 *     php bin/Worker.php
 *
 * IMPORTANT — production deployment: this script does not daemonize, fork,
 * or background itself, and it does not restart itself if it crashes or the
 * process is killed. In production it must be kept running and
 * automatically restarted by a real process supervisor — systemd (a
 * `Restart=always` unit), supervisord, or an equivalent container/
 * orchestrator restart policy. Phase 30 finalizes the actual deployment and
 * supervisor configuration; this phase only builds and documents the daemon
 * itself. Until that supervisor exists, treat this as a foreground process
 * you start and watch by hand.
 *
 * Shutdown: SIGTERM/SIGINT are trapped (`pcntl` when available) and stop the
 * loop *after* the in-flight batch finishes — never mid-batch — so a
 * supervisor's stop/restart never interrupts a job partway through.
 *
 * Env overrides:
 *   JOBS_WORKER_BATCH_SIZE    max jobs claimed per poll (default 50)
 *   JOBS_WORKER_POLL_SECONDS  sleep between polls once nothing was due (default 5)
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Shared\Application\Jobs\RunDueJobsHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$batchSizeEnv = getenv('JOBS_WORKER_BATCH_SIZE');
$batchSize = max(1, (int) ($batchSizeEnv !== false && $batchSizeEnv !== '' ? $batchSizeEnv : 50));

$pollSecondsEnv = getenv('JOBS_WORKER_POLL_SECONDS');
$pollSeconds = max(1, (int) ($pollSecondsEnv !== false && $pollSecondsEnv !== '' ? $pollSecondsEnv : 5));

$hostname = gethostname();
$workerId = 'worker:' . ($hostname !== false ? $hostname : 'unknown') . ':' . getmypid();

$runner = ContainerFactory::create()->get(RunDueJobsHandler::class);
assert($runner instanceof RunDueJobsHandler);

$shouldStop = false;

if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $requestStop = static function (int $signal) use (&$shouldStop): void {
        fwrite(STDOUT, \sprintf("[%s] %s received signal %d — stopping after the current batch\n", date('c'), 'worker', $signal));
        $shouldStop = true;
    };
    pcntl_signal(SIGTERM, $requestStop);
    pcntl_signal(SIGINT, $requestStop);
} else {
    fwrite(STDERR, \sprintf(
        "[%s] pcntl extension not available — SIGTERM/SIGINT will kill this process immediately instead of stopping gracefully after the current batch.\n",
        date('c'),
    ));
}

fwrite(STDOUT, \sprintf("[%s] %s starting (batch_size=%d poll_seconds=%d)\n", date('c'), $workerId, $batchSize, $pollSeconds));

while (!$shouldStop) {
    $summary = $runner->run($batchSize, $workerId);

    if ($summary['attempted'] > 0) {
        fwrite(STDOUT, \sprintf(
            "[%s] %s attempted=%d succeeded=%d failed=%d unhandled=%d\n",
            date('c'),
            $workerId,
            $summary['attempted'],
            $summary['succeeded'],
            $summary['failed'],
            $summary['unhandled'],
        ));

        // More due jobs may already be waiting behind this batch (e.g. a
        // burst right after startup) — drain immediately rather than
        // sleeping. Every job type here is self-rescheduling minutes into
        // the future once dispatched, so this can't busy-loop indefinitely
        // under normal operation: the very next poll almost always finds
        // nothing due and falls through to the sleep below.
        continue;
    }

    sleep($pollSeconds);
}

fwrite(STDOUT, \sprintf("[%s] %s stopped\n", date('c'), $workerId));
exit(0);
