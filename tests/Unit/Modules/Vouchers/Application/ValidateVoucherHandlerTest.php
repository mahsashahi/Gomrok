<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Vouchers\Application\ValidateVoucher\ValidateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\ValidateVoucher\ValidateVoucherHandler;
use Gomrok\Modules\Vouchers\Application\ValidateVoucher\ValidateVoucherResult;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\InMemoryVoucherCurrencyDiscountRepository;
use Gomrok\Tests\Support\InMemoryVoucherEligibilityRuleRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\StubPackageDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidateVoucherHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    #[Test]
    public function eligibleVoucherReturnsADiscountPreviewWithoutReservingAnything(): void
    {
        $handler = $this->handler();
        $redemptions = $this->redemptions;

        $result = $handler->handle(new ValidateVoucherCommand(
            clientId: self::CLIENT,
            packageCode: 'pro',
            country: 'DE',
            voucherCode: 'WELCOME10',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof ValidateVoucherResult);

        self::assertTrue($value->eligible);
        self::assertSame([], $value->reasons);
        self::assertSame('WELCOME10', $value->voucherCode);
        self::assertSame(2900, $value->priceAmountMinor);
        self::assertSame('EUR', $value->currencyCode);
        self::assertSame(290, $value->nominalDiscountMinor);
        self::assertSame(290, $value->appliedDiscountMinor);
        self::assertSame(2610, $value->payableMinor);

        // A pure preview: nothing was reserved.
        self::assertSame(0, $redemptions->activeReservations($this->voucherId));
    }

    #[Test]
    public function ineligibleVoucherReturnsReasonsAndNoDiscount(): void
    {
        $handler = $this->handler();

        $result = $handler->handle(new ValidateVoucherCommand(
            clientId: self::CLIENT,
            packageCode: 'pro',
            country: 'DE',
            voucherCode: 'LIMITED1',
            clientUserRef: 'user-1',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof ValidateVoucherResult);

        self::assertFalse($value->eligible);
        self::assertContains('voucher.user_limit_reached', $value->reasons);
        self::assertNull($value->nominalDiscountMinor);
        self::assertNull($value->appliedDiscountMinor);
        self::assertNull($value->payableMinor);
    }

    #[Test]
    public function unknownVoucherCodeIsNotFound(): void
    {
        $result = $this->handler()->handle(new ValidateVoucherCommand(
            clientId: self::CLIENT,
            packageCode: 'pro',
            country: 'DE',
            voucherCode: 'GHOST',
        ));

        self::assertTrue($result->isErr());
        self::assertSame('voucher.not_found', $result->error()->code);
    }

    #[Test]
    public function unknownPackageIsNotFound(): void
    {
        $result = $this->handler()->handle(new ValidateVoucherCommand(
            clientId: self::CLIENT,
            packageCode: 'ghost',
            country: 'DE',
            voucherCode: 'WELCOME10',
        ));

        self::assertTrue($result->isErr());
        self::assertSame('package.not_found', $result->error()->code);
    }

    #[Test]
    public function anUnknownPaymentMethodIsAValidationError(): void
    {
        $result = $this->handler()->handle(new ValidateVoucherCommand(
            clientId: self::CLIENT,
            packageCode: 'pro',
            country: 'DE',
            voucherCode: 'WELCOME10',
            paymentMethod: 'not-a-method',
        ));

        self::assertTrue($result->isErr());
        self::assertSame('voucher_validate.unknown_method', $result->error()->code);
    }

    private InMemoryVoucherRedemptionRepository $redemptions;
    private int $voucherId;

    private function handler(): ValidateVoucherHandler
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-11T12:00:00+00:00');

        $directory = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro');

        $groups = new InMemoryPricingGroupRepository();
        $groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now));
        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));

        $priceResolver = new PriceResolver(
            $groups,
            new InMemoryPricingGroupPackageRepository(),
            $defaults,
            new InMemoryClientExchangeRateRepository(),
            $directory,
            new PriceListResolver(new InMemoryPriceListRepository(), new InMemoryPriceListPackageRepository()),
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            $clock,
        );

        $vouchers = new InMemoryVoucherRepository();
        $voucher = Voucher::create(self::CLIENT, 'WELCOME10', 'Welcome', null, null, null, false, null, null, DefaultDiscountType::Percentage, 1000, $now);
        $vouchers->save($voucher);
        $voucherId = $voucher->id();
        \assert($voucherId !== null);
        $this->voucherId = $voucherId;

        // A second, once-per-user voucher already used by "user-1", so the ineligible-path
        // test has a real reason to fail on without disturbing the happy-path voucher above.
        $limited = Voucher::create(self::CLIENT, 'LIMITED1', 'Limited', null, null, null, false, null, null, DefaultDiscountType::Percentage, 500, $now);
        $vouchers->save($limited);
        $limitedId = $limited->id();
        \assert($limitedId !== null);
        $limited->setUsageLimits(null, 1, null, $now);
        $vouchers->save($limited);

        $this->redemptions = new InMemoryVoucherRedemptionRepository();
        $reserved = \Gomrok\Modules\Vouchers\Domain\VoucherRedemption::reserve($limitedId, self::CLIENT, 'user-1', 'prior-order', 'EUR', 2900, 145, 145, 2755, $now);
        $this->redemptions->save($reserved);
        $confirmError = $reserved->confirm($now);
        self::assertNull($confirmError);
        $this->redemptions->save($reserved);

        $evaluator = new VoucherEligibilityEvaluator(
            new InMemoryVoucherEligibilityRuleRepository(),
            new InMemoryVoucherCurrencyDiscountRepository(),
            $this->redemptions,
        );
        $calculator = new VoucherDiscountCalculator(new InMemoryVoucherCurrencyDiscountRepository());

        return new ValidateVoucherHandler($directory, $priceResolver, $vouchers, $evaluator, $calculator, $clock);
    }
}
