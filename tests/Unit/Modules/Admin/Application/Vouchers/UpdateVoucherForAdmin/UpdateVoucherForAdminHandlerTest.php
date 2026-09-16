<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin;

use Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin\UpdateVoucherForAdminCommand;
use Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin\UpdateVoucherForAdminHandler;
use Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus\ChangeVoucherStatusHandler;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherHandler;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherResult;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherHandler;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateVoucherForAdminHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryVoucherRepository $vouchers;
    private UpdateVoucherForAdminHandler $handler;
    private int $voucherId;

    protected function setUp(): void
    {
        $clock = new FrozenClock('2026-09-16T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();
        $reference = new InMemoryReferenceCatalog();

        $this->vouchers = new InMemoryVoucherRepository();

        $created = (new CreateVoucherHandler(
            $this->vouchers,
            new StubClientDirectory(self::CLIENT),
            $reference,
            $audit,
            $transactions,
            $clock,
        ))->handle(new CreateVoucherCommand(self::CLIENT, 'WELCOME10', 'Welcome', defaultDiscountType: 'percentage', defaultPercentBp: 1000));

        $payload = $created->value();
        self::assertInstanceOf(CreateVoucherResult::class, $payload);
        $this->voucherId = $payload->voucherId;

        $this->handler = new UpdateVoucherForAdminHandler(
            new UpdateVoucherHandler($this->vouchers, $reference, $audit, $transactions, $clock),
            new ChangeVoucherStatusHandler($this->vouchers, $audit, $transactions, $clock),
        );
    }

    private function command(
        string $name = 'Welcome',
        bool $active = true,
        string $defaultDiscountType = 'percentage',
        ?int $defaultPercentBp = 1000,
        bool $firstPurchaseOnly = false,
    ): UpdateVoucherForAdminCommand {
        return new UpdateVoucherForAdminCommand(
            clientId: self::CLIENT,
            voucherId: $this->voucherId,
            name: $name,
            description: null,
            validFrom: null,
            validUntil: null,
            firstPurchaseOnly: $firstPurchaseOnly,
            minPurchaseMinor: null,
            minPurchaseCurrency: null,
            defaultDiscountType: $defaultDiscountType,
            defaultPercentBp: $defaultPercentBp,
            active: $active,
        );
    }

    #[Test]
    public function updatesDescriptiveFieldsAndKeepsTheVoucherActive(): void
    {
        $result = $this->handler->handle($this->command(name: 'Welcome Renamed', firstPurchaseOnly: true));

        self::assertTrue($result->isOk());
        $voucher = $this->vouchers->findById($this->voucherId);
        self::assertNotNull($voucher);
        self::assertSame('Welcome Renamed', $voucher->name());
        self::assertTrue($voucher->firstPurchaseOnly());
        self::assertTrue($voucher->isActive());
    }

    #[Test]
    public function disablesTheVoucherInTheSameSubmit(): void
    {
        $result = $this->handler->handle($this->command(active: false));

        self::assertTrue($result->isOk());
        self::assertFalse($this->vouchers->findById($this->voucherId)?->isActive());
    }

    #[Test]
    public function reEnablesADisabledVoucher(): void
    {
        $this->handler->handle($this->command(active: false));

        $result = $this->handler->handle($this->command(active: true));

        self::assertTrue($result->isOk());
        self::assertTrue($this->vouchers->findById($this->voucherId)?->isActive());
    }

    #[Test]
    public function aRejectedUpdateNeverReachesTheStatusChange(): void
    {
        // `fixed` is not a legal default discount type — it is inherently
        // currency-bound and only ever exists as an override (Voucher.md §4).
        $result = $this->handler->handle($this->command(active: false, defaultDiscountType: 'fixed', defaultPercentBp: null));

        self::assertTrue($result->isErr());
        self::assertTrue($this->vouchers->findById($this->voucherId)?->isActive(), 'status must be untouched when the update itself failed');
    }

    #[Test]
    public function scopesToTheOwningClient(): void
    {
        $result = $this->handler->handle(new UpdateVoucherForAdminCommand(
            clientId: 999,
            voucherId: $this->voucherId,
            name: 'Hijack',
            description: null,
            validFrom: null,
            validUntil: null,
            firstPurchaseOnly: false,
            minPurchaseMinor: null,
            minPurchaseCurrency: null,
            defaultDiscountType: 'percentage',
            defaultPercentBp: 1000,
            active: true,
        ));

        self::assertTrue($result->isErr());
        self::assertSame('voucher.not_found', $result->error()->code);
        self::assertSame('Welcome', $this->vouchers->findById($this->voucherId)?->name());
    }
}
