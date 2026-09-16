<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Vouchers;

use Gomrok\Modules\Admin\Application\Vouchers\VouchersScreenHandler;
use Gomrok\Modules\Vouchers\Application\VoucherRedemptionSummary;
use Gomrok\Modules\Vouchers\Application\VoucherSummary;
use Gomrok\Tests\Support\StubVoucherDirectory;
use Gomrok\Tests\Support\StubVoucherRedemptionDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VouchersScreenHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const VOUCHER = 42;

    private StubVoucherDirectory $vouchers;
    private StubVoucherRedemptionDirectory $redemptions;
    private VouchersScreenHandler $handler;

    protected function setUp(): void
    {
        $this->vouchers = new StubVoucherDirectory();
        $this->redemptions = new StubVoucherRedemptionDirectory();
        $this->handler = new VouchersScreenHandler($this->vouchers, $this->redemptions);
    }

    /**
     * @param list<array{dimension: string, value: string}> $eligibilityRules
     * @param list<array{currency: string, discount_type: string, percent_bp: ?int, amount_minor: ?int, max_discount_minor: ?int}> $currencyDiscounts
     */
    private function voucher(
        string $code = 'WELCOME10',
        string $defaultDiscountType = 'percentage',
        ?int $defaultPercentBp = 1000,
        array $eligibilityRules = [],
        array $currencyDiscounts = [],
        ?int $maxTotalRedemptions = null,
        ?int $maxPerUser = null,
        int $redeemedCount = 0,
        string $status = 'active',
    ): VoucherSummary {
        return new VoucherSummary(
            self::VOUCHER,
            self::CLIENT,
            $code,
            'Welcome offer',
            'Ten percent off',
            $status,
            null,
            null,
            false,
            null,
            null,
            $defaultDiscountType,
            $defaultPercentBp,
            $maxTotalRedemptions,
            $maxPerUser,
            null,
            $redeemedCount,
            $eligibilityRules,
            $currencyDiscounts,
        );
    }

    #[Test]
    public function listsVouchersAndSelectsTheRequestedOne(): void
    {
        $this->vouchers->add($this->voucher());

        $result = $this->handler->forClient(self::CLIENT, 'WELCOME10');

        self::assertCount(1, $result->vouchers);
        self::assertTrue($result->vouchers[0]->selected);
        self::assertNotNull($result->selected);
        self::assertSame('WELCOME10', $result->selected->code);
        self::assertSame('10%', $result->selected->defaultDiscountLabel);
    }

    #[Test]
    public function fallsBackToTheFirstVoucherWhenNoneIsRequested(): void
    {
        $this->vouchers->add($this->voucher());

        $result = $this->handler->forClient(self::CLIENT, null);

        self::assertNotNull($result->selected);
        self::assertSame('WELCOME10', $result->selected->code);
    }

    #[Test]
    public function labelsEachDefaultDiscountKind(): void
    {
        $full = new VouchersScreenHandler(
            (new StubVoucherDirectory())->add($this->voucher(defaultDiscountType: 'full', defaultPercentBp: null)),
            $this->redemptions,
        );
        self::assertSame('100% (full)', $full->forClient(self::CLIENT, null)->selected?->defaultDiscountLabel);

        // `none` with no override rows discounts nothing anywhere (Voucher.md §4).
        $none = new VouchersScreenHandler(
            (new StubVoucherDirectory())->add($this->voucher(defaultDiscountType: 'none', defaultPercentBp: null)),
            $this->redemptions,
        );
        self::assertSame('No discount configured', $none->forClient(self::CLIENT, null)->selected?->defaultDiscountLabel);

        // …but with an override row it is a deliberate per-currency-only voucher.
        $perCurrency = new VouchersScreenHandler(
            (new StubVoucherDirectory())->add($this->voucher(
                defaultDiscountType: 'none',
                defaultPercentBp: null,
                currencyDiscounts: [['currency' => 'EUR', 'discount_type' => 'fixed', 'percent_bp' => null, 'amount_minor' => 500, 'max_discount_minor' => null]],
            )),
            $this->redemptions,
        );
        self::assertSame('Per-currency only', $perCurrency->forClient(self::CLIENT, null)->selected?->defaultDiscountLabel);
    }

    #[Test]
    public function formatsCurrencyOverridesWithTheirCaps(): void
    {
        $this->vouchers->add($this->voucher(currencyDiscounts: [
            ['currency' => 'EUR', 'discount_type' => 'fixed', 'percent_bp' => null, 'amount_minor' => 500, 'max_discount_minor' => null],
            ['currency' => 'USD', 'discount_type' => 'percentage', 'percent_bp' => 5000, 'amount_minor' => null, 'max_discount_minor' => 1000],
        ]));

        $detail = $this->handler->forClient(self::CLIENT, null)->selected;
        self::assertNotNull($detail);
        $rows = $detail->currencyDiscounts;

        self::assertCount(2, $rows);
        self::assertSame('€5.00', $rows[0]->discountLabel);
        self::assertNull($rows[0]->capLabel);
        self::assertSame('50%', $rows[1]->discountLabel);
        self::assertSame('max $10.00', $rows[1]->capLabel);
    }

    #[Test]
    public function foldsEligibilityRulesByDimensionAndPrefillsEveryDimension(): void
    {
        $this->vouchers->add($this->voucher(eligibilityRules: [
            ['dimension' => 'country', 'value' => 'DE'],
            ['dimension' => 'country', 'value' => 'NL'],
            ['dimension' => 'currency', 'value' => 'EUR'],
        ]));

        $detail = $this->handler->forClient(self::CLIENT, null)->selected;
        self::assertNotNull($detail);

        self::assertCount(2, $detail->eligibilityGroups);
        self::assertSame('country', $detail->eligibilityGroups[0]->dimension);
        self::assertSame(['DE', 'NL'], $detail->eligibilityGroups[0]->values);

        // Every dimension gets a prefill entry, restricted or not.
        self::assertSame('DE, NL', $detail->eligibilityPrefill['country']);
        self::assertSame('EUR', $detail->eligibilityPrefill['currency']);
        self::assertSame('', $detail->eligibilityPrefill['package']);
        self::assertSame('', $detail->eligibilityPrefill['subscription_interval']);
    }

    #[Test]
    public function marksARedemptionWhoseDiscountWasClamped(): void
    {
        $this->vouchers->add($this->voucher());
        $this->redemptions
            // 50% of €29.00 capped at €5.00 — nominal €14.50, applied €5.00.
            ->add(new VoucherRedemptionSummary(1, self::VOUCHER, 'user-1', 'att-1', 'confirmed', 'EUR', 2900, 1450, 500, 2400, '2026-09-16 12:00:00', '2026-09-16 12:05:00', null))
            ->add(new VoucherRedemptionSummary(2, self::VOUCHER, 'user-2', 'att-2', 'reserved', 'EUR', 2900, 290, 290, 2610, '2026-09-16 13:00:00', null, null));

        $detail = $this->handler->forClient(self::CLIENT, null)->selected;
        self::assertNotNull($detail);
        $rows = $detail->redemptions;

        self::assertCount(2, $rows);
        self::assertTrue($rows[0]->wasClamped);
        self::assertSame('€14.50', $rows[0]->nominalDiscountLabel);
        self::assertSame('€5.00', $rows[0]->appliedDiscountLabel);
        self::assertSame('€24.00', $rows[0]->payableLabel);
        self::assertFalse($rows[1]->wasClamped);
    }

    #[Test]
    public function summarisesUsageAgainstTheConfiguredCaps(): void
    {
        $this->vouchers->add($this->voucher(maxTotalRedemptions: 100, maxPerUser: 1, redeemedCount: 12));

        $detail = $this->handler->forClient(self::CLIENT, null)->selected;

        self::assertSame('12 / 100 used · once per user', $detail?->usageLabel);
    }

    #[Test]
    public function anUnknownClientHasNoVouchers(): void
    {
        $this->vouchers->add($this->voucher());

        $result = $this->handler->forClient(999, null);

        self::assertSame([], $result->vouchers);
        self::assertNull($result->selected);
    }
}
