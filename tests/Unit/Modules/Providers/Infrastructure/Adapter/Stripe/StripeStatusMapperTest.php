<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Infrastructure\Adapter\Stripe;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 21's own exit criterion: unit tests for status mapping.
 */
final class StripeStatusMapperTest extends TestCase
{
    private StripeStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new StripeStatusMapper();
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: PaymentStatus}>
     */
    public static function checkoutSessionCases(): array
    {
        return [
            'complete + paid -> Paid' => ['complete', 'paid', PaymentStatus::Paid],
            'complete + no_payment_required -> Paid' => ['complete', 'no_payment_required', PaymentStatus::Paid],
            'complete + unpaid -> Pending (rare, awaiting a follow-up event)' => ['complete', 'unpaid', PaymentStatus::Pending],
            'open -> Pending' => ['open', 'unpaid', PaymentStatus::Pending],
            'expired -> Expired' => ['expired', 'unpaid', PaymentStatus::Expired],
            'unrecognised session status -> Pending, never a false claim' => ['some_future_status', 'paid', PaymentStatus::Pending],
        ];
    }

    #[Test]
    #[DataProvider('checkoutSessionCases')]
    public function mapsCheckoutSessionStatuses(string $sessionStatus, ?string $paymentStatus, PaymentStatus $expected): void
    {
        self::assertSame($expected, $this->mapper->fromCheckoutSession($sessionStatus, $paymentStatus));
    }

    /**
     * @return array<string, array{0: string, 1: PaymentStatus}>
     */
    public static function paymentIntentCases(): array
    {
        return [
            'requires_payment_method -> Pending' => ['requires_payment_method', PaymentStatus::Pending],
            'requires_confirmation -> Pending' => ['requires_confirmation', PaymentStatus::Pending],
            'requires_action -> RequiresAction' => ['requires_action', PaymentStatus::RequiresAction],
            'processing -> Pending' => ['processing', PaymentStatus::Pending],
            'requires_capture -> Authorized' => ['requires_capture', PaymentStatus::Authorized],
            'succeeded -> Paid' => ['succeeded', PaymentStatus::Paid],
            'canceled -> Canceled' => ['canceled', PaymentStatus::Canceled],
            'unrecognised payment intent status -> Pending, never a false claim' => ['some_future_status', PaymentStatus::Pending],
        ];
    }

    #[Test]
    #[DataProvider('paymentIntentCases')]
    public function mapsPaymentIntentStatuses(string $paymentIntentStatus, PaymentStatus $expected): void
    {
        self::assertSame($expected, $this->mapper->fromPaymentIntent($paymentIntentStatus));
    }

    #[Test]
    public function mappingIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame(PaymentStatus::Paid, $this->mapper->fromPaymentIntent('  SUCCEEDED  '));
        self::assertSame(PaymentStatus::Paid, $this->mapper->fromCheckoutSession(' Complete ', ' PAID '));
    }

    #[Test]
    public function subscriptionStatusIsAProvisionalNormalizedPassThrough(): void
    {
        self::assertSame('active', $this->mapper->fromSubscription('active'));
        self::assertSame('past_due', $this->mapper->fromSubscription('  Past_Due  '));
    }
}
