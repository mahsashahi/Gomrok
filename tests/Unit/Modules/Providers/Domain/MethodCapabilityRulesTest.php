<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Domain;

use Gomrok\Modules\Providers\Domain\MethodCapabilityRules;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MethodCapabilityRulesTest extends TestCase
{
    #[Test]
    public function molliePayPalDropsRecurringAndSubscription(): void
    {
        $constraint = MethodCapabilityRules::constraintFor('mollie', PaymentMethod::PayPal);

        self::assertFalse($constraint->isEmpty());
        self::assertContains(PurchaseType::Subscription, $constraint->excludedPurchaseTypes);
        self::assertContains(PurchaseType::RecurringPayment, $constraint->excludedPurchaseTypes);
        self::assertNotContains(PurchaseType::OneTimePayment, $constraint->excludedPurchaseTypes);
    }

    #[Test]
    public function mollieCardHasNoConstraint(): void
    {
        self::assertTrue(MethodCapabilityRules::constraintFor('mollie', PaymentMethod::Card)->isEmpty());
    }

    #[Test]
    public function stripeCardHasNoConstraint(): void
    {
        self::assertTrue(MethodCapabilityRules::constraintFor('stripe', PaymentMethod::Card)->isEmpty());
    }
}
