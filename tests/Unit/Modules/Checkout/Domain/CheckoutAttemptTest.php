<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckoutAttemptTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
    }

    #[Test]
    public function startsInTheStartedState(): void
    {
        $attempt = $this->attempt();

        self::assertSame(CheckoutAttemptStatus::Started, $attempt->status());
        self::assertSame('order-1', $attempt->attemptReference());
    }

    #[Test]
    public function skippingRanksIsAllowed(): void
    {
        $attempt = $this->attempt();

        // no voucher: pricing_resolved -> provider_selected directly, skipping voucher_reserved
        self::assertNull($attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now));
        self::assertNull($attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now));
        self::assertSame(CheckoutAttemptStatus::ProviderSelected, $attempt->status());
    }

    #[Test]
    public function sameStatusIsAnIdempotentNoOp(): void
    {
        $attempt = $this->attempt();
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);

        self::assertNull($attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now));
        self::assertSame(CheckoutAttemptStatus::PricingResolved, $attempt->status());
    }

    #[Test]
    public function movingBackwardIsRejected(): void
    {
        $attempt = $this->attempt();
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);

        self::assertSame('checkout_attempt.invalid_transition', $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now)?->code);
        self::assertSame(CheckoutAttemptStatus::ProviderSelected, $attempt->status());
    }

    #[Test]
    public function convertedToPaymentRequiresConfirmedFirst(): void
    {
        $attempt = $this->attempt();
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);

        self::assertSame('checkout_attempt.not_confirmed', $attempt->transitionTo(CheckoutAttemptStatus::ConvertedToPayment, $this->now)?->code);

        $attempt->transitionTo(CheckoutAttemptStatus::ProviderCheckoutCreated, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::RedirectedToProvider, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ReturnedFromProvider, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::Confirmed, $this->now);

        self::assertNull($attempt->transitionTo(CheckoutAttemptStatus::ConvertedToPayment, $this->now));
        self::assertSame(CheckoutAttemptStatus::ConvertedToPayment, $attempt->status());
    }

    #[Test]
    public function anExitStatusIsReachableFromAnyNonTerminalStatus(): void
    {
        $atStart = $this->attempt();
        self::assertNull($atStart->transitionTo(CheckoutAttemptStatus::Canceled, $this->now));

        $atProviderSelected = $this->attempt();
        $atProviderSelected->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $atProviderSelected->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);
        self::assertNull($atProviderSelected->transitionTo(CheckoutAttemptStatus::Failed, $this->now, 'declined', 'Card declined'));
        self::assertSame('declined', $atProviderSelected->errorCode());
        self::assertSame('Card declined', $atProviderSelected->errorMessage());
    }

    #[Test]
    public function noTransitionIsAllowedOnceTerminal(): void
    {
        $attempt = $this->attempt();
        $attempt->transitionTo(CheckoutAttemptStatus::Canceled, $this->now);

        self::assertSame('checkout_attempt.terminal', $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now)?->code);
        self::assertSame('checkout_attempt.terminal', $attempt->transitionTo(CheckoutAttemptStatus::Failed, $this->now)?->code);
    }

    #[Test]
    public function commercialSnapshotReflectsTheAttemptsOwnContext(): void
    {
        $attempt = $this->attempt();
        $attempt->assignId(42);

        $snapshot = $attempt->commercialSnapshot();

        self::assertSame(42, $snapshot['checkout_attempt_id']);
        self::assertSame(7, $snapshot['client_id']);
        self::assertSame('order-1', $snapshot['attempt_reference']);
        self::assertSame(PurchaseType::OneTimePayment->value, $snapshot['purchase_type']);
        self::assertSame('started', $snapshot['status']);
    }

    private function attempt(): CheckoutAttempt
    {
        return CheckoutAttempt::start(7, 'user-1', ' order-1 ', 42, 'de', 'eur', PurchaseType::OneTimePayment, null, null, $this->now);
    }
}
