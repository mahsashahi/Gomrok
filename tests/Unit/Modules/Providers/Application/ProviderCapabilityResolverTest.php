<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Application;

use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilityResolverTest extends TestCase
{
    private ProviderCapabilityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders());
    }

    #[Test]
    public function stripeIsAFullFeaturedProvider(): void
    {
        self::assertTrue($this->resolver->supportsPurchaseType('stripe', PurchaseType::Subscription));
        self::assertTrue($this->resolver->supportsPurchaseType('stripe', PurchaseType::AutoCharge));
        self::assertTrue($this->resolver->supports('stripe', Capability::PartialRefund));
        self::assertTrue($this->resolver->supports('stripe', Capability::CustomerPortal));
    }

    #[Test]
    public function ziraatIsChargeOnly(): void
    {
        // exit matrix — Stripe vs Ziraat
        self::assertTrue($this->resolver->supportsPurchaseType('ziraat', PurchaseType::OneTimePayment));
        self::assertFalse($this->resolver->supportsPurchaseType('ziraat', PurchaseType::Subscription));
        self::assertFalse($this->resolver->supportsPurchaseType('ziraat', PurchaseType::AutoCharge));
        self::assertFalse($this->resolver->supportsPurchaseType('ziraat', PurchaseType::RecurringPayment));

        self::assertFalse($this->resolver->supports('ziraat', Capability::Refund));
        self::assertFalse($this->resolver->supports('ziraat', Capability::SubscriptionCancel));
        self::assertTrue($this->resolver->supports('ziraat', Capability::ManualStatusPolling));
        self::assertTrue($this->resolver->supports('ziraat', Capability::ThreeDSecure));
    }

    #[Test]
    public function mollieCardKeepsSubscriptionButMolliePayPalDoesNot(): void
    {
        // exit matrix — Mollie card vs PayPal
        self::assertTrue($this->resolver->supportsPurchaseType('mollie', PurchaseType::Subscription, PaymentMethod::Card));
        self::assertTrue($this->resolver->supports('mollie', Capability::SubscriptionCancel, PaymentMethod::Card));

        self::assertFalse($this->resolver->supportsPurchaseType('mollie', PurchaseType::Subscription, PaymentMethod::PayPal));
        self::assertFalse($this->resolver->supportsPurchaseType('mollie', PurchaseType::RecurringPayment, PaymentMethod::PayPal));
        self::assertFalse($this->resolver->supports('mollie', Capability::SubscriptionCancel, PaymentMethod::PayPal));

        // one-time still works via PayPal
        self::assertTrue($this->resolver->supportsPurchaseType('mollie', PurchaseType::OneTimePayment, PaymentMethod::PayPal));
        self::assertTrue($this->resolver->supports('mollie', Capability::Refund, PaymentMethod::PayPal));
    }

    #[Test]
    public function resolveReturnsTheNarrowedSet(): void
    {
        $resolved = $this->resolver->resolve('mollie', PaymentMethod::PayPal);

        self::assertNotNull($resolved);
        self::assertSame(PaymentMethod::PayPal, $resolved->method);
        self::assertSame(
            [PurchaseType::OneTimePayment],
            $resolved->purchaseTypes,
        );
    }

    #[Test]
    public function unknownProviderResolvesToNothing(): void
    {
        self::assertNull($this->resolver->resolve('braintree'));
        self::assertFalse($this->resolver->supports('braintree', Capability::Refund));
        self::assertFalse($this->resolver->supportsPurchaseType('braintree', PurchaseType::OneTimePayment));
    }
}
