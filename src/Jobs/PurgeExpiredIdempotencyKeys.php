<?php

declare(strict_types=1);

namespace Gomrok\Jobs;

use Gomrok\Shared\Application\Idempotency\IdempotencyStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes idempotency-key rows past their TTL. Correctness never depends on this
 * running — {@see IdempotencyStore::claim()} already ignores an expired row — it
 * just keeps the table small.
 *
 * A plain invokable: run it from `bin/PurgeIdempotencyKeys.php` now, wire it into
 * the scheduler when the job runner lands (later phase). The same shape will be
 * reused for other TTL purges (voucher reservations, expired payments).
 */
final readonly class PurgeExpiredIdempotencyKeys
{
    public function __construct(
        private IdempotencyStore $store,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): int
    {
        $deleted = $this->store->purgeExpired($this->clock->now());

        $this->logger->info('job.purge_expired_idempotency_keys', ['deleted' => $deleted]);

        return $deleted;
    }
}
