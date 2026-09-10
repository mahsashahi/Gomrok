<?php

declare(strict_types=1);

/**
 * CLI entrypoint for {@see \Gomrok\Jobs\PurgeExpiredIdempotencyKeys}.
 *
 * Run ad hoc (`composer idempotency:purge`) or from cron until the job runner
 * exists:
 *
 *     * /10 * * * *  php /path/to/gomrok/bin/PurgeIdempotencyKeys.php
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Jobs\PurgeExpiredIdempotencyKeys;

require dirname(__DIR__) . '/vendor/autoload.php';

$job = ContainerFactory::create()->get(PurgeExpiredIdempotencyKeys::class);
assert($job instanceof PurgeExpiredIdempotencyKeys);

$deleted = $job();

fwrite(STDOUT, sprintf("purged %d expired idempotency key(s)\n", $deleted));

exit(0);
