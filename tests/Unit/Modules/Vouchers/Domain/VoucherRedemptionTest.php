<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Vouchers\Domain\RedemptionStatus;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VoucherRedemptionTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
    }

    #[Test]
    public function reserveStartsInTheReservedState(): void
    {
        $redemption = $this->redemption();

        self::assertTrue($redemption->isReserved());
        self::assertSame(RedemptionStatus::Reserved, $redemption->status());
        self::assertNull($redemption->confirmedAt());
        self::assertNull($redemption->releasedAt());
    }

    #[Test]
    public function confirmIsIdempotentAndBlocksAfterRelease(): void
    {
        $redemption = $this->redemption();

        self::assertNull($redemption->confirm($this->now));
        self::assertSame(RedemptionStatus::Confirmed, $redemption->status());
        self::assertNotNull($redemption->confirmedAt());

        // confirming again is a no-op, not an error
        self::assertNull($redemption->confirm($this->now));
        self::assertSame(RedemptionStatus::Confirmed, $redemption->status());

        $released = $this->redemption();
        $released->release($this->now);
        self::assertSame('voucher_redemption.already_released', $released->confirm($this->now)?->code);
    }

    #[Test]
    public function releaseIsIdempotentAndBlocksAfterConfirm(): void
    {
        $redemption = $this->redemption();

        self::assertNull($redemption->release($this->now));
        self::assertSame(RedemptionStatus::Released, $redemption->status());
        self::assertNotNull($redemption->releasedAt());

        // releasing again is a no-op, not an error
        self::assertNull($redemption->release($this->now));
        self::assertSame(RedemptionStatus::Released, $redemption->status());

        $confirmed = $this->redemption();
        $confirmed->confirm($this->now);
        self::assertSame('voucher_redemption.already_confirmed', $confirmed->release($this->now)?->code);
    }

    private function redemption(): VoucherRedemption
    {
        return VoucherRedemption::reserve(1, 7, 'user-1', 'attempt-1', 'eur', 2900, 500, 500, 2400, $this->now);
    }
}
