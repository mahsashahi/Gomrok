<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\Jobs;

use Gomrok\Modules\Vouchers\Application\ReleaseVoucherRedemption\ReleaseVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;
use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;
use Psr\Clock\ClockInterface;

/**
 * The stale-voucher-reservation sweep named since Phase 17 Q2 and formally
 * assigned to Phase 29 (`Architecture.md` §13). A `reserved` row whose
 * `reserved_at` is older than {@see STALE_AFTER_MINUTES} almost certainly
 * belongs to an abandoned checkout that never confirmed or explicitly
 * released it — reusing {@see ReleaseVoucherRedemptionHandler} unchanged
 * (same locking, idempotency, and audit trail a real release already gets)
 * rather than mutating the redemption directly here.
 */
final readonly class VoucherReservationSweepHandler implements JobHandler
{
    private const STALE_AFTER_MINUTES = 60;
    private const BATCH_SIZE = 100;
    private const RECURRENCE_MINUTES = 5;

    public function __construct(
        private VoucherRedemptionRepository $redemptions,
        private ReleaseVoucherRedemptionHandler $release,
        private ClockInterface $clock,
    ) {
    }

    public function type(): string
    {
        return 'voucher_reservation_sweep';
    }

    public function recurrenceIntervalMinutes(): int
    {
        return self::RECURRENCE_MINUTES;
    }

    public function handle(Job $job): JobRunResult
    {
        $before = $this->clock->now()->modify('-' . self::STALE_AFTER_MINUTES . ' minutes');
        $stale = $this->redemptions->findStaleReserved($before, self::BATCH_SIZE);

        $released = 0;
        foreach ($stale as $redemption) {
            $result = $this->release->handle($redemption->voucherId(), $redemption->attemptReference(), $redemption->clientId());
            if ($result->isOk()) {
                ++$released;
            }
        }

        return JobRunResult::success(['scanned' => \count($stale), 'released' => $released]);
    }
}
