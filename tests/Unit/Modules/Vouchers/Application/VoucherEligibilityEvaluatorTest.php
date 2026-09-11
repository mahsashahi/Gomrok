<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Vouchers\Application\VoucherContext;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\DiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscount;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityDimension;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRule;
use Gomrok\Modules\Vouchers\Domain\VoucherStatus;
use Gomrok\Tests\Support\InMemoryVoucherCurrencyDiscountRepository;
use Gomrok\Tests\Support\InMemoryVoucherEligibilityRuleRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Phase 16 exit criterion: eligibility evaluation across every dimension.
 */
final class VoucherEligibilityEvaluatorTest extends TestCase
{
    private const CLIENT = 7;
    private const VOUCHER_ID = 1;

    private InMemoryVoucherEligibilityRuleRepository $rules;
    private InMemoryVoucherCurrencyDiscountRepository $currencyDiscounts;
    private VoucherEligibilityEvaluator $evaluator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $this->rules = new InMemoryVoucherEligibilityRuleRepository();
        $this->currencyDiscounts = new InMemoryVoucherCurrencyDiscountRepository();
        $this->evaluator = new VoucherEligibilityEvaluator($this->rules, $this->currencyDiscounts);
    }

    #[Test]
    public function aPlainActiveVoucherIsEligibleWithAnEmptyContext(): void
    {
        $voucher = $this->voucher();

        $result = $this->evaluator->evaluate($voucher, $this->context());

        self::assertTrue($result->eligible);
        self::assertSame([], $result->reasons);
    }

    #[Test]
    public function disabledVoucherIsNotEligible(): void
    {
        $voucher = $this->voucher();
        $voucher->disable($this->now);

        $result = $this->evaluator->evaluate($voucher, $this->context());

        self::assertFalse($result->eligible);
        self::assertContains('voucher.disabled', $result->reasons);
    }

    #[Test]
    public function windowIsEnforced(): void
    {
        $notYet = $this->voucher(validFrom: new DateTimeImmutable('2027-01-01'));
        $expired = $this->voucher(validUntil: new DateTimeImmutable('2026-01-01'));

        self::assertContains('voucher.not_yet_valid', $this->evaluator->evaluate($notYet, $this->context())->reasons);
        self::assertContains('voucher.expired', $this->evaluator->evaluate($expired, $this->context())->reasons);
    }

    #[Test]
    public function wrongClientIsRejected(): void
    {
        $voucher = $this->voucher();

        $result = $this->evaluator->evaluate($voucher, $this->context(clientId: 999));

        self::assertContains('voucher.wrong_client', $result->reasons);
    }

    #[Test]
    public function everyScopingDimensionIsCheckedAndOrsWithinItself(): void
    {
        $voucher = $this->voucher();
        $this->rules->replaceForVoucher(self::VOUCHER_ID, [
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::Country, 'DE'),
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::Country, 'AT'),
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::Currency, 'EUR'),
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::Package, '42'),
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::ProviderAccount, '5'),
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::PaymentMethod, PaymentMethod::Card->value),
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::PurchaseType, PurchaseType::Subscription->value),
            new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::SubscriptionInterval, SubscriptionInterval::Yearly->value),
        ]);

        $mismatch = $this->evaluator->evaluate($voucher, new VoucherContext(
            self::CLIENT,
            $this->now,
            'FR',
            'USD',
            43,
            6,
            PaymentMethod::PayPal,
            PurchaseType::OneTimePayment,
            SubscriptionInterval::Monthly,
        ));
        self::assertSame([
            'voucher.country_not_eligible',
            'voucher.currency_not_eligible',
            'voucher.package_not_eligible',
            'voucher.provider_not_eligible',
            'voucher.method_not_eligible',
            'voucher.purchase_type_not_eligible',
            'voucher.interval_not_eligible',
        ], $mismatch->reasons);

        // "AT" satisfies the country OR; every other dimension matches too.
        $match = $this->evaluator->evaluate($voucher, new VoucherContext(
            self::CLIENT,
            $this->now,
            'AT',
            'EUR',
            42,
            5,
            PaymentMethod::Card,
            PurchaseType::Subscription,
            SubscriptionInterval::Yearly,
        ));
        self::assertTrue($match->eligible);
    }

    #[Test]
    public function missingContextValueFailsARestrictedDimension(): void
    {
        $voucher = $this->voucher();
        $this->rules->replaceForVoucher(self::VOUCHER_ID, [new VoucherEligibilityRule(self::VOUCHER_ID, VoucherEligibilityDimension::Country, 'DE')]);

        $result = $this->evaluator->evaluate($voucher, $this->context(country: null));

        self::assertContains('voucher.country_not_eligible', $result->reasons);
    }

    #[Test]
    public function aNoneDefaultNeedsACurrencyOverrideToBeApplicable(): void
    {
        $voucher = $this->voucher(defaultType: DefaultDiscountType::None);

        $withoutOverride = $this->evaluator->evaluate($voucher, $this->context(currency: 'EUR'));
        self::assertContains('voucher.no_discount_for_currency', $withoutOverride->reasons);

        $this->currencyDiscounts->save(new VoucherCurrencyDiscount(self::VOUCHER_ID, 'EUR', DiscountType::Fixed, null, 500, null));
        $withOverride = $this->evaluator->evaluate($voucher, $this->context(currency: 'EUR'));
        self::assertTrue($withOverride->eligible);
    }

    #[Test]
    public function aPercentageOrFullDefaultNeedsNoOverride(): void
    {
        $voucher = $this->voucher(defaultType: DefaultDiscountType::Percentage, defaultPercentBp: 500);

        $result = $this->evaluator->evaluate($voucher, $this->context(currency: 'TRY'));

        self::assertTrue($result->eligible);
    }

    #[Test]
    public function minimumPurchaseOnlyComparesWithinTheSameCurrency(): void
    {
        $voucher = $this->voucher(minPurchaseMinor: 1000, minPurchaseCurrency: 'EUR');

        $below = $this->evaluator->evaluate($voucher, $this->context(amountMinor: 500, amountCurrency: 'EUR'));
        self::assertContains('voucher.below_minimum', $below->reasons);

        $exactly = $this->evaluator->evaluate($voucher, $this->context(amountMinor: 1000, amountCurrency: 'EUR'));
        self::assertTrue($exactly->eligible);

        // different currency -> not compared, not rejected
        $crossCurrency = $this->evaluator->evaluate($voucher, $this->context(amountMinor: 100, amountCurrency: 'USD'));
        self::assertTrue($crossCurrency->eligible);
    }

    #[Test]
    public function firstPurchaseOnlyDistinguishesUnknownFromFalse(): void
    {
        $voucher = $this->voucher(firstPurchaseOnly: true);

        self::assertContains('voucher.first_purchase_unknown', $this->evaluator->evaluate($voucher, $this->context(isFirstPurchase: null))->reasons);
        self::assertContains('voucher.not_first_purchase', $this->evaluator->evaluate($voucher, $this->context(isFirstPurchase: false))->reasons);
        self::assertTrue($this->evaluator->evaluate($voucher, $this->context(isFirstPurchase: true))->eligible);
    }

    #[Test]
    public function theGlobalUsageCapIsEnforced(): void
    {
        $exhausted = Voucher::fromStorage(
            self::VOUCHER_ID,
            self::CLIENT,
            'CAP',
            'Cap',
            null,
            VoucherStatus::Active,
            null,
            null,
            false,
            null,
            null,
            DefaultDiscountType::None,
            null,
            10,
            null,
            null,
            10,
            $this->now,
            null,
        );
        $notYet = Voucher::fromStorage(
            self::VOUCHER_ID,
            self::CLIENT,
            'CAP',
            'Cap',
            null,
            VoucherStatus::Active,
            null,
            null,
            false,
            null,
            null,
            DefaultDiscountType::None,
            null,
            10,
            null,
            null,
            9,
            $this->now,
            null,
        );

        self::assertContains('voucher.exhausted', $this->evaluator->evaluate($exhausted, $this->context())->reasons);
        self::assertNotContains('voucher.exhausted', $this->evaluator->evaluate($notYet, $this->context())->reasons);
    }

    private function voucher(
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        DefaultDiscountType $defaultType = DefaultDiscountType::Full,
        ?int $defaultPercentBp = null,
        ?int $minPurchaseMinor = null,
        ?string $minPurchaseCurrency = null,
        bool $firstPurchaseOnly = false,
    ): Voucher {
        $voucher = Voucher::create(
            self::CLIENT,
            'TEST',
            'Test',
            null,
            $validFrom,
            $validUntil,
            $firstPurchaseOnly,
            $minPurchaseMinor,
            $minPurchaseCurrency,
            $defaultType,
            $defaultPercentBp,
            $this->now,
        );
        $voucher->assignId(self::VOUCHER_ID);

        return $voucher;
    }

    private function context(
        int $clientId = self::CLIENT,
        ?string $country = 'DE',
        ?string $currency = 'EUR',
        ?int $amountMinor = null,
        ?string $amountCurrency = null,
        ?bool $isFirstPurchase = null,
    ): VoucherContext {
        return new VoucherContext($clientId, $this->now, $country, $currency, amountMinor: $amountMinor, amountCurrency: $amountCurrency, isFirstPurchase: $isFirstPurchase);
    }
}
