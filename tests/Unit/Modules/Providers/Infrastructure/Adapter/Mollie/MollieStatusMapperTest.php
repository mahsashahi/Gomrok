<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Infrastructure\Adapter\Mollie;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Mollie\MollieStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MollieStatusMapperTest extends TestCase
{
    private MollieStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MollieStatusMapper();
    }

    /**
     * @return iterable<string, array{string, PaymentStatus}>
     */
    public static function paymentStatuses(): iterable
    {
        yield 'open' => ['open', PaymentStatus::Pending];
        yield 'pending' => ['pending', PaymentStatus::Pending];
        yield 'authorized' => ['authorized', PaymentStatus::Authorized];
        yield 'paid' => ['paid', PaymentStatus::Paid];
        yield 'failed' => ['failed', PaymentStatus::Failed];
        yield 'canceled' => ['canceled', PaymentStatus::Canceled];
        yield 'expired' => ['expired', PaymentStatus::Expired];
        yield 'case and whitespace insensitive' => [' PAID ', PaymentStatus::Paid];
        yield 'unrecognised falls back to Pending' => ['some_future_status', PaymentStatus::Pending];
    }

    #[Test]
    #[DataProvider('paymentStatuses')]
    public function mapsMolliePaymentStatuses(string $raw, PaymentStatus $expected): void
    {
        self::assertSame($expected, $this->mapper->fromPaymentStatus($raw));
    }

    #[Test]
    public function subscriptionMappingIsAProvisionalPassThrough(): void
    {
        self::assertSame('paid', $this->mapper->fromSubscription(' Paid '));
    }
}
