<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Domain;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Phase 20 Q2 transition graph exactly.
 */
final class PaymentStatusTest extends TestCase
{
    #[Test]
    public function theGraphMatchesTheDecidedDesign(): void
    {
        self::assertSame(
            [PaymentStatus::Pending, PaymentStatus::Canceled, PaymentStatus::Failed],
            PaymentStatus::Created->allowedNextStatuses(),
        );
        self::assertSame(
            [PaymentStatus::RequiresAction, PaymentStatus::Authorized, PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Expired],
            PaymentStatus::Pending->allowedNextStatuses(),
        );
        self::assertSame(
            [PaymentStatus::Authorized, PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Expired],
            PaymentStatus::RequiresAction->allowedNextStatuses(),
        );
        self::assertSame(
            [PaymentStatus::Paid, PaymentStatus::Canceled, PaymentStatus::Expired, PaymentStatus::Failed],
            PaymentStatus::Authorized->allowedNextStatuses(),
        );
        self::assertSame(
            [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Disputed],
            PaymentStatus::Paid->allowedNextStatuses(),
        );
        self::assertSame(
            [PaymentStatus::Refunded, PaymentStatus::Disputed],
            PaymentStatus::PartiallyRefunded->allowedNextStatuses(),
        );
        self::assertSame(
            [PaymentStatus::Chargeback, PaymentStatus::Paid],
            PaymentStatus::Disputed->allowedNextStatuses(),
        );
    }

    #[Test]
    public function refundedCanceledExpiredFailedAndChargebackAreTerminal(): void
    {
        foreach ([PaymentStatus::Refunded, PaymentStatus::Canceled, PaymentStatus::Expired, PaymentStatus::Failed, PaymentStatus::Chargeback] as $status) {
            self::assertTrue($status->isTerminal(), "{$status->value} should be terminal");
            self::assertSame([], $status->allowedNextStatuses());
        }
    }

    #[Test]
    public function nonTerminalStatusesAreNotTerminal(): void
    {
        foreach ([PaymentStatus::Created, PaymentStatus::Pending, PaymentStatus::RequiresAction, PaymentStatus::Authorized, PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Disputed] as $status) {
            self::assertFalse($status->isTerminal(), "{$status->value} should not be terminal");
        }
    }
}
