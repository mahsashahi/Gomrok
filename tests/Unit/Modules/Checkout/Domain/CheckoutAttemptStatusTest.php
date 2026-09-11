<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Domain;

use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckoutAttemptStatusTest extends TestCase
{
    #[Test]
    public function theHappyPathIsRankedInOrder(): void
    {
        self::assertSame(1, CheckoutAttemptStatus::Started->rank());
        self::assertSame(2, CheckoutAttemptStatus::PricingResolved->rank());
        self::assertSame(3, CheckoutAttemptStatus::VoucherReserved->rank());
        self::assertSame(4, CheckoutAttemptStatus::ProviderSelected->rank());
        self::assertSame(5, CheckoutAttemptStatus::ProviderCheckoutCreated->rank());
        self::assertSame(6, CheckoutAttemptStatus::RedirectedToProvider->rank());
        self::assertSame(7, CheckoutAttemptStatus::ReturnedFromProvider->rank());
        self::assertSame(8, CheckoutAttemptStatus::Confirmed->rank());
        self::assertSame(9, CheckoutAttemptStatus::ConvertedToPayment->rank());
    }

    #[Test]
    public function theFourExitsHaveNoRank(): void
    {
        foreach ([CheckoutAttemptStatus::Failed, CheckoutAttemptStatus::Canceled, CheckoutAttemptStatus::Expired, CheckoutAttemptStatus::Abandoned] as $exit) {
            self::assertNull($exit->rank());
            self::assertTrue($exit->isExit());
            self::assertTrue($exit->isTerminal());
        }
    }

    #[Test]
    public function convertedToPaymentIsTerminalButNotAnExit(): void
    {
        self::assertTrue(CheckoutAttemptStatus::ConvertedToPayment->isTerminal());
        self::assertFalse(CheckoutAttemptStatus::ConvertedToPayment->isExit());
    }

    #[Test]
    public function happyPathStatusesAreNotTerminal(): void
    {
        foreach ([
            CheckoutAttemptStatus::Started,
            CheckoutAttemptStatus::PricingResolved,
            CheckoutAttemptStatus::VoucherReserved,
            CheckoutAttemptStatus::ProviderSelected,
            CheckoutAttemptStatus::ProviderCheckoutCreated,
            CheckoutAttemptStatus::RedirectedToProvider,
            CheckoutAttemptStatus::ReturnedFromProvider,
            CheckoutAttemptStatus::Confirmed,
        ] as $status) {
            self::assertFalse($status->isTerminal());
            self::assertFalse($status->isExit());
        }
    }
}
