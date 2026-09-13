<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Infrastructure\Adapter\PayPal;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal\PayPalStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PayPalStatusMapperTest extends TestCase
{
    private PayPalStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new PayPalStatusMapper();
    }

    /**
     * @return iterable<string, array{string, PaymentStatus}>
     */
    public static function statuses(): iterable
    {
        // Order
        yield 'CREATED' => ['CREATED', PaymentStatus::Pending];
        yield 'SAVED' => ['SAVED', PaymentStatus::Pending];
        yield 'APPROVED' => ['APPROVED', PaymentStatus::Pending];
        yield 'PAYER_ACTION_REQUIRED' => ['PAYER_ACTION_REQUIRED', PaymentStatus::RequiresAction];
        yield 'VOIDED' => ['VOIDED', PaymentStatus::Canceled];
        yield 'COMPLETED' => ['COMPLETED', PaymentStatus::Paid];
        // Authorization
        yield 'CAPTURED' => ['CAPTURED', PaymentStatus::Paid];
        yield 'DENIED' => ['DENIED', PaymentStatus::Failed];
        yield 'EXPIRED' => ['EXPIRED', PaymentStatus::Expired];
        yield 'PARTIALLY_CAPTURED' => ['PARTIALLY_CAPTURED', PaymentStatus::Authorized];
        // Capture
        yield 'DECLINED' => ['DECLINED', PaymentStatus::Failed];
        yield 'PARTIALLY_REFUNDED' => ['PARTIALLY_REFUNDED', PaymentStatus::PartiallyRefunded];
        yield 'PENDING' => ['PENDING', PaymentStatus::Pending];
        yield 'REFUNDED' => ['REFUNDED', PaymentStatus::Refunded];
        yield 'FAILED' => ['FAILED', PaymentStatus::Failed];
        yield 'case and whitespace insensitive' => [' completed ', PaymentStatus::Paid];
        yield 'unrecognised falls back to Pending' => ['SOME_FUTURE_STATUS', PaymentStatus::Pending];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function mapsPayPalStatuses(string $raw, PaymentStatus $expected): void
    {
        self::assertSame($expected, $this->mapper->fromStatus($raw));
    }
}
