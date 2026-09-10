<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Application\PriceRuleContext;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Domain\PriceRule;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceRuleResolverTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const GROUP = 3;

    private InMemoryPriceRuleRepository $rules;
    private PriceRuleResolver $resolver;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $this->rules = new InMemoryPriceRuleRepository();
        $this->resolver = new PriceRuleResolver($this->rules);
    }

    #[Test]
    public function noRuleMatchesReturnsNull(): void
    {
        $this->rule(providerAccountId: 99, amount: 100);

        self::assertNull($this->resolver->resolve(self::CLIENT, self::PACKAGE, $this->context(providerAccountId: 1)));
    }

    #[Test]
    public function aWildcardOnlyRuleMatchesAnyRequest(): void
    {
        $this->rule(currency: 'EUR', amount: 2500);

        $match = $this->resolver->resolve(self::CLIENT, self::PACKAGE, $this->context());
        self::assertNotNull($match);
        self::assertSame(2500, $match->amountMinor());
    }

    #[Test]
    public function moreMatchedDimensionsWins(): void
    {
        $general = $this->rule(paymentMethod: PaymentMethod::Card, amount: 2400);
        $specific = $this->rule(paymentMethod: PaymentMethod::Card, countryCode: 'DE', amount: 2200);

        $match = $this->resolver->resolve(self::CLIENT, self::PACKAGE, $this->context(country: 'DE', method: PaymentMethod::Card));

        self::assertSame($specific, $match?->id());
        self::assertNotSame($general, $match->id());
    }

    #[Test]
    public function onEqualCountTheHigherPriorityDimensionWins(): void
    {
        // both pin exactly one dimension: purchase_type beats country in the priority list
        $byCountry = $this->rule(countryCode: 'DE', amount: 2600);
        $byType = $this->rule(purchaseType: PurchaseType::Subscription, amount: 2100);

        $match = $this->resolver->resolve(self::CLIENT, self::PACKAGE, $this->context(country: 'DE', purchaseType: PurchaseType::Subscription));

        self::assertSame($byType, $match?->id());
        self::assertNotSame($byCountry, $match->id());
    }

    #[Test]
    public function aMoreSpecificUnavailableRuleBeatsAGeneralAvailableOne(): void
    {
        $this->rule(purchaseType: PurchaseType::Subscription, amount: 2000);
        $unavailable = $this->rule(purchaseType: PurchaseType::Subscription, subscriptionInterval: SubscriptionInterval::Yearly, available: false);

        $match = $this->resolver->resolve(self::CLIENT, self::PACKAGE, $this->context(
            purchaseType: PurchaseType::Subscription,
            interval: SubscriptionInterval::Yearly,
        ));

        self::assertSame($unavailable, $match?->id());
        self::assertFalse($match->isAvailable());
    }

    #[Test]
    public function pinnedDimensionsAreListedMostSpecificFirst(): void
    {
        $this->rule(countryCode: 'DE', paymentMethod: PaymentMethod::Card, currency: 'EUR', amount: 2000);

        $match = $this->resolver->resolve(self::CLIENT, self::PACKAGE, $this->context(country: 'DE', method: PaymentMethod::Card));
        self::assertNotNull($match);
        self::assertSame(['payment_method', 'currency_code', 'country_code'], $match->pinnedDimensions());
    }

    private function rule(
        ?int $pricingGroupId = null,
        ?string $countryCode = null,
        ?int $providerAccountId = null,
        ?PaymentMethod $paymentMethod = null,
        ?PurchaseType $purchaseType = null,
        ?SubscriptionInterval $subscriptionInterval = null,
        ?string $currency = null,
        bool $available = true,
        ?int $amount = null,
    ): int {
        $rule = PriceRule::create(
            self::CLIENT,
            self::PACKAGE,
            $pricingGroupId,
            $countryCode,
            $providerAccountId,
            $paymentMethod,
            $purchaseType,
            $subscriptionInterval,
            $currency,
            $available,
            $amount,
            $this->now,
        );
        $this->rules->save($rule);
        $id = $rule->id();
        \assert($id !== null);

        return $id;
    }

    private function context(
        string $country = 'DE',
        string $currency = 'EUR',
        ?int $providerAccountId = null,
        ?PaymentMethod $method = null,
        ?PurchaseType $purchaseType = null,
        ?SubscriptionInterval $interval = null,
    ): PriceRuleContext {
        return new PriceRuleContext(self::GROUP, $country, $currency, $providerAccountId, $method, $purchaseType, $interval);
    }
}
