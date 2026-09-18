<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Vouchers\Application\Jobs\VoucherReservationSweepHandler;
use Gomrok\Modules\Vouchers\Application\ReleaseVoucherRedemption\ReleaseVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VoucherReservationSweepHandlerTest extends TestCase
{
    private InMemoryVoucherRepository $vouchers;
    private InMemoryVoucherRedemptionRepository $redemptions;
    private FrozenClock $clock;
    private VoucherReservationSweepHandler $handler;

    protected function setUp(): void
    {
        $this->vouchers = new InMemoryVoucherRepository();
        $this->redemptions = new InMemoryVoucherRedemptionRepository();
        $this->clock = new FrozenClock('2026-09-17T12:00:00+00:00');

        $release = new ReleaseVoucherRedemptionHandler(
            $this->vouchers,
            $this->redemptions,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            $this->clock,
        );

        $this->handler = new VoucherReservationSweepHandler($this->redemptions, $release, $this->clock);
    }

    private function seedVoucher(int $clientId = 1): int
    {
        $voucher = Voucher::create(
            $clientId,
            'SAVE10',
            'Save 10',
            null,
            null,
            null,
            false,
            null,
            null,
            DefaultDiscountType::Percentage,
            1000,
            $this->clock->now(),
        );
        $this->vouchers->save($voucher);
        $id = $voucher->id();
        \assert($id !== null);

        return $id;
    }

    private function seedReservation(int $voucherId, string $reservedAt, string $attemptReference = 'attempt-1', int $clientId = 1): void
    {
        $redemption = VoucherRedemption::reserve(
            $voucherId,
            $clientId,
            null,
            $attemptReference,
            'USD',
            1000,
            100,
            100,
            900,
            new DateTimeImmutable($reservedAt),
        );
        $this->redemptions->save($redemption);
    }

    #[Test]
    public function exposesItsTypeAndRecurrence(): void
    {
        self::assertSame('voucher_reservation_sweep', $this->handler->type());
        self::assertSame(5, $this->handler->recurrenceIntervalMinutes());
    }

    #[Test]
    public function releasesAStaleReservationAndTalliesTheSummary(): void
    {
        $voucherId = $this->seedVoucher();
        $this->seedReservation($voucherId, '2026-09-17T10:00:00+00:00');

        $job = Job::schedule('voucher_reservation_sweep', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertTrue($result->success);
        self::assertSame(['scanned' => 1, 'released' => 1], $result->summary);
    }

    #[Test]
    public function leavesAFreshReservationUntouched(): void
    {
        $voucherId = $this->seedVoucher();
        $this->seedReservation($voucherId, '2026-09-17T11:55:00+00:00');

        $job = Job::schedule('voucher_reservation_sweep', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertSame(['scanned' => 0, 'released' => 0], $result->summary);
    }
}
