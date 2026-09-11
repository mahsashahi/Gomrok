<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Application;

use Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus\ChangeVoucherStatusHandler;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherHandler;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherResult;
use Gomrok\Modules\Vouchers\Application\RemoveVoucherCurrencyDiscount\RemoveVoucherCurrencyDiscountHandler;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountHandler;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountResult;
use Gomrok\Modules\Vouchers\Application\SetVoucherEligibility\SetVoucherEligibilityCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherEligibility\SetVoucherEligibilityHandler;
use Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits\SetVoucherUsageLimitsCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits\SetVoucherUsageLimitsHandler;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherHandler;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\InMemoryVoucherCurrencyDiscountRepository;
use Gomrok\Tests\Support\InMemoryVoucherEligibilityRuleRepository;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VoucherHandlersTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private InMemoryVoucherRepository $vouchers;
    private InMemoryVoucherEligibilityRuleRepository $rules;
    private InMemoryVoucherCurrencyDiscountRepository $discounts;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->vouchers = new InMemoryVoucherRepository();
        $this->rules = new InMemoryVoucherEligibilityRuleRepository();
        $this->discounts = new InMemoryVoucherCurrencyDiscountRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function createValidatesCodeAndUniquenessAndDiscount(): void
    {
        $handler = $this->createHandler();

        self::assertSame('voucher.invalid_code', $handler->handle(new CreateVoucherCommand(self::CLIENT, 'x', 'X'))->error()->code);
        self::assertSame('voucher.percent_out_of_range', $handler->handle(new CreateVoucherCommand(self::CLIENT, 'PCT', 'Pct', defaultDiscountType: 'percentage'))->error()->code);

        $ok = $handler->handle(new CreateVoucherCommand(self::CLIENT, 'welcome10', 'Welcome', defaultDiscountType: 'percentage', defaultPercentBp: 1000));
        self::assertTrue($ok->isOk());
        $payload = $ok->value();
        self::assertInstanceOf(CreateVoucherResult::class, $payload);

        self::assertSame('voucher.code_taken', $handler->handle(new CreateVoucherCommand(self::CLIENT, 'WELCOME10', 'Dup'))->error()->code);
        self::assertContains('voucher.created', $this->audit->actions());

        $voucher = $this->vouchers->findById($payload->voucherId);
        self::assertNotNull($voucher);
        self::assertSame('WELCOME10', $voucher->code());
    }

    #[Test]
    public function updateScopesToClientAndRevalidates(): void
    {
        $created = $this->createHandler()->handle(new CreateVoucherCommand(self::CLIENT, 'VOU1', 'VOU1'));
        $payload = $created->value();
        self::assertInstanceOf(CreateVoucherResult::class, $payload);

        $handler = new UpdateVoucherHandler($this->vouchers, new InMemoryReferenceCatalog(), $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame('voucher.not_found', $handler->handle(new UpdateVoucherCommand(999, $payload->voucherId, 'X'))->error()->code);

        $ok = $handler->handle(new UpdateVoucherCommand(self::CLIENT, $payload->voucherId, 'Renamed', firstPurchaseOnly: true));
        self::assertTrue($ok->isOk());
        $updated = $this->vouchers->findById($payload->voucherId);
        self::assertSame('Renamed', $updated?->name());
        self::assertTrue($updated->firstPurchaseOnly());
        self::assertContains('voucher.updated', $this->audit->actions());
    }

    #[Test]
    public function setEligibilityValidatesEveryDimension(): void
    {
        $created = $this->createHandler()->handle(new CreateVoucherCommand(self::CLIENT, 'VOU2', 'VOU2'));
        $payload = $created->value();
        self::assertInstanceOf(CreateVoucherResult::class, $payload);

        $handler = new SetVoucherEligibilityHandler(
            $this->vouchers,
            $this->rules,
            (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro'),
            (new StubProviderAccountDirectory())->add(5, self::CLIENT, 'stripe-test', 'stripe'),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
        );

        self::assertSame('voucher_eligibility.unknown_dimension', $handler->handle(new SetVoucherEligibilityCommand(self::CLIENT, $payload->voucherId, [['dimension' => 'bogus', 'value' => 'x']]))->error()->code);
        self::assertSame('voucher_eligibility.unknown_country', $handler->handle(new SetVoucherEligibilityCommand(self::CLIENT, $payload->voucherId, [['dimension' => 'country', 'value' => 'ZZ']]))->error()->code);
        self::assertSame('voucher_eligibility.unknown_package', $handler->handle(new SetVoucherEligibilityCommand(self::CLIENT, $payload->voucherId, [['dimension' => 'package', 'value' => '999']]))->error()->code);
        self::assertSame('voucher_eligibility.unknown_provider_account', $handler->handle(new SetVoucherEligibilityCommand(self::CLIENT, $payload->voucherId, [['dimension' => 'provider_account', 'value' => '999']]))->error()->code);
        self::assertSame('voucher_eligibility.unknown_method', $handler->handle(new SetVoucherEligibilityCommand(self::CLIENT, $payload->voucherId, [['dimension' => 'payment_method', 'value' => 'crypto']]))->error()->code);

        $ok = $handler->handle(new SetVoucherEligibilityCommand(self::CLIENT, $payload->voucherId, [
            ['dimension' => 'country', 'value' => 'de'],
            ['dimension' => 'package', 'value' => (string) self::PACKAGE],
        ]));
        self::assertTrue($ok->isOk());
        self::assertCount(2, $this->rules->forVoucher($payload->voucherId));
        self::assertContains('voucher.eligibility_set', $this->audit->actions());

        // full replace: setting again with fewer rules drops the old ones
        $handler->handle(new SetVoucherEligibilityCommand(self::CLIENT, $payload->voucherId, [['dimension' => 'country', 'value' => 'DE']]));
        self::assertCount(1, $this->rules->forVoucher($payload->voucherId));
    }

    #[Test]
    public function currencyDiscountUpsertsAndCanBeRemoved(): void
    {
        $created = $this->createHandler()->handle(new CreateVoucherCommand(self::CLIENT, 'VOU3', 'VOU3', defaultDiscountType: 'none'));
        $payload = $created->value();
        self::assertInstanceOf(CreateVoucherResult::class, $payload);

        $set = new SetVoucherCurrencyDiscountHandler($this->vouchers, $this->discounts, new InMemoryReferenceCatalog(), $this->audit, new SynchronousTransactions());
        $remove = new RemoveVoucherCurrencyDiscountHandler($this->vouchers, $this->discounts, $this->audit, new SynchronousTransactions());

        self::assertSame(
            'voucher_currency_discount.amount_required',
            $set->handle(new SetVoucherCurrencyDiscountCommand(self::CLIENT, $payload->voucherId, 'EUR', 'fixed'))->error()->code,
        );

        $first = $set->handle(new SetVoucherCurrencyDiscountCommand(self::CLIENT, $payload->voucherId, 'eur', 'fixed', amountMinor: 500));
        $firstPayload = $first->value();
        self::assertInstanceOf(SetVoucherCurrencyDiscountResult::class, $firstPayload);
        self::assertTrue($firstPayload->created);

        $second = $set->handle(new SetVoucherCurrencyDiscountCommand(self::CLIENT, $payload->voucherId, 'EUR', 'fixed', amountMinor: 600));
        $secondPayload = $second->value();
        self::assertInstanceOf(SetVoucherCurrencyDiscountResult::class, $secondPayload);
        self::assertFalse($secondPayload->created);
        self::assertSame(600, $this->discounts->find($payload->voucherId, 'EUR')?->amountMinor);

        self::assertTrue($remove->handle($payload->voucherId, 'EUR', self::CLIENT)->isOk());
        self::assertNull($this->discounts->find($payload->voucherId, 'EUR'));
        self::assertSame('voucher_currency_discount.not_found', $remove->handle($payload->voucherId, 'EUR', self::CLIENT)->error()->code);
    }

    #[Test]
    public function usageLimitsAcceptTheCanonicalOncePerUserExample(): void
    {
        $created = $this->createHandler()->handle(new CreateVoucherCommand(self::CLIENT, 'VOU4', 'VOU4'));
        $payload = $created->value();
        self::assertInstanceOf(CreateVoucherResult::class, $payload);

        $handler = new SetVoucherUsageLimitsHandler($this->vouchers, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame('voucher.invalid_limit', $handler->handle(new SetVoucherUsageLimitsCommand(self::CLIENT, $payload->voucherId, maxPerUser: 0))->error()->code);

        $ok = $handler->handle(new SetVoucherUsageLimitsCommand(self::CLIENT, $payload->voucherId, maxTotalRedemptions: null, maxPerUser: 1, maxPerClient: null));
        self::assertTrue($ok->isOk());
        $voucher = $this->vouchers->findById($payload->voucherId);
        self::assertNotNull($voucher);
        self::assertNull($voucher->maxTotalRedemptions());
        self::assertSame(1, $voucher->maxPerUser());
        self::assertNull($voucher->maxPerClient());
    }

    #[Test]
    public function statusTogglesAndIsIdempotent(): void
    {
        $created = $this->createHandler()->handle(new CreateVoucherCommand(self::CLIENT, 'VOU5', 'VOU5'));
        $payload = $created->value();
        self::assertInstanceOf(CreateVoucherResult::class, $payload);

        $handler = new ChangeVoucherStatusHandler($this->vouchers, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertTrue($handler->disable($payload->voucherId, self::CLIENT)->isOk());
        self::assertFalse($this->vouchers->findById($payload->voucherId)?->isActive());
        self::assertTrue($handler->disable($payload->voucherId, self::CLIENT)->isOk());
        self::assertTrue($handler->enable($payload->voucherId, self::CLIENT)->isOk());
        self::assertTrue($this->vouchers->findById($payload->voucherId)?->isActive());
        self::assertSame('voucher.not_found', $handler->disable(999, self::CLIENT)->error()->code);
    }

    private function createHandler(): CreateVoucherHandler
    {
        return new CreateVoucherHandler(
            $this->vouchers,
            new StubClientDirectory(self::CLIENT),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }
}
