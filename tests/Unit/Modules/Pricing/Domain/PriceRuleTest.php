<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PriceRule;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceRuleTest extends TestCase
{
    #[Test]
    public function validateRejectsInconsistentRules(): void
    {
        // interval without a subscription/recurring purchase type
        self::assertSame(
            'price_rule.interval_needs_subscription',
            PriceRule::validate(null, PurchaseType::OneTimePayment, SubscriptionInterval::Yearly, true, 100, 'EUR')?->code,
        );
        // available but no amount
        self::assertSame(
            'price_rule.amount_required',
            PriceRule::validate(1, null, null, true, null, 'EUR')?->code,
        );
        // available with an amount but neither group nor currency pinned
        self::assertSame(
            'price_rule.needs_group_or_currency',
            PriceRule::validate(null, null, null, true, 100, null)?->code,
        );
        // unavailable but carries an amount
        self::assertSame(
            'price_rule.unavailable_has_amount',
            PriceRule::validate(null, null, null, false, 100, null)?->code,
        );

        self::assertNull(PriceRule::validate(1, null, null, true, 2500, null));
        self::assertNull(PriceRule::validate(null, PurchaseType::Subscription, SubscriptionInterval::Monthly, false, null, null));
    }

    #[Test]
    public function createClearsAmountForAnUnavailableRule(): void
    {
        $rule = PriceRule::create(7, 42, null, null, null, null, null, null, null, false, 999, new DateTimeImmutable('now'));

        self::assertFalse($rule->isAvailable());
        self::assertNull($rule->amountMinor());
    }

    #[Test]
    public function matchesRespectsEveryPinnedDimension(): void
    {
        $rule = PriceRule::create(
            7,
            42,
            3,
            'DE',
            null,
            PaymentMethod::Card,
            null,
            null,
            'EUR',
            true,
            2500,
            new DateTimeImmutable('now'),
        );

        self::assertTrue($rule->matches(3, 'DE', null, PaymentMethod::Card, null, null, 'EUR'));
        self::assertFalse($rule->matches(4, 'DE', null, PaymentMethod::Card, null, null, 'EUR'));      // wrong group
        self::assertFalse($rule->matches(3, 'FR', null, PaymentMethod::Card, null, null, 'EUR'));      // wrong country
        self::assertFalse($rule->matches(3, 'DE', null, PaymentMethod::PayPal, null, null, 'EUR'));    // wrong method
        self::assertFalse($rule->matches(3, 'DE', null, PaymentMethod::Card, null, null, 'USD'));      // wrong currency
        self::assertTrue($rule->matches(3, 'DE', 99, PaymentMethod::Card, PurchaseType::Subscription, null, 'EUR')); // extra request dims are fine

        self::assertSame(4, $rule->specificity()); // group + country + method + currency
    }
}
