<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Application\ChangePriceListStatus\ChangePriceListStatusHandler;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListCommand;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListHandler;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListResult;
use Gomrok\Modules\Pricing\Application\SetPriceListFactor\SetPriceListFactorCommand;
use Gomrok\Modules\Pricing\Application\SetPriceListFactor\SetPriceListFactorHandler;
use Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice\SetPriceListPackagePriceCommand;
use Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice\SetPriceListPackagePriceHandler;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceListHandlersTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private InMemoryPriceListRepository $lists;
    private InMemoryPriceListPackageRepository $listPackages;
    private InMemoryPricingGroupRepository $groups;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;
    private int $groupId;
    private int $controlId;

    protected function setUp(): void
    {
        $this->lists = new InMemoryPriceListRepository();
        $this->listPackages = new InMemoryPriceListPackageRepository();
        $this->groups = new InMemoryPricingGroupRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-10T12:00:00+00:00');

        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $group = PricingGroup::define(self::CLIENT, PricingGroupSlug::of('dach'), 'DACH', 1, null, 'EUR', false, $now);
        $this->groups->save($group);
        $id = $group->id();
        self::assertNotNull($id);
        $this->groupId = $id;

        $control = PriceList::control(self::CLIENT, $this->groupId, $now);
        $this->lists->save($control);
        $controlId = $control->id();
        self::assertNotNull($controlId);
        $this->controlId = $controlId;
    }

    #[Test]
    public function createValidatesGroupOwnershipNameAndFactor(): void
    {
        $handler = $this->createHandler();

        self::assertSame('price_list.group_not_owned', $handler->handle(new CreatePriceListCommand(self::CLIENT, 999, 'List B', '0.9'))->error()->code);
        self::assertSame('price_list.reserved_name', $handler->handle(new CreatePriceListCommand(self::CLIENT, $this->groupId, 'List A · control', '0.9'))->error()->code);
        self::assertSame('price_list.non_positive_factor', $handler->handle(new CreatePriceListCommand(self::CLIENT, $this->groupId, 'List B', '0'))->error()->code);

        $ok = $handler->handle(new CreatePriceListCommand(self::CLIENT, $this->groupId, 'List B', '0.9'));
        self::assertInstanceOf(CreatePriceListResult::class, $ok->value());
        self::assertSame('price_list.name_taken', $handler->handle(new CreatePriceListCommand(self::CLIENT, $this->groupId, 'List B', '0.8'))->error()->code);
        self::assertContains('price_list.created', $this->audit->actions());
    }

    #[Test]
    public function theControlListCannotBeDisabled(): void
    {
        $status = new ChangePriceListStatusHandler($this->lists, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame('price_list.cannot_disable_control', $status->disable($this->controlId, self::CLIENT)->error()->code);
        self::assertSame('price_list.not_found', $status->disable(12345, self::CLIENT)->error()->code);
    }

    #[Test]
    public function anExperimentListEnablesDisablesAndRefactors(): void
    {
        $listB = $this->experiment('List B', '0.9');
        $status = new ChangePriceListStatusHandler($this->lists, $this->audit, new SynchronousTransactions(), $this->clock);
        $factor = new SetPriceListFactorHandler($this->lists, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertTrue($status->disable($listB, self::CLIENT)->isOk());
        self::assertFalse($this->lists->findById($listB)?->isEnabled());
        self::assertTrue($status->enable($listB, self::CLIENT)->isOk());

        self::assertTrue($factor->handle(new SetPriceListFactorCommand(self::CLIENT, $listB, '1.1500'))->isOk());
        self::assertSame('1.1500', $this->lists->findById($listB)?->factor());
        self::assertSame('price_list.control_factor_locked', $factor->handle(new SetPriceListFactorCommand(self::CLIENT, $this->controlId, '1.2'))->error()->code);
    }

    #[Test]
    public function setPackagePriceRejectsControlAndCurrencyMismatch(): void
    {
        $listB = $this->experiment('List B', '0.9');
        $handler = new SetPriceListPackagePriceHandler(
            $this->lists,
            $this->listPackages,
            $this->groups,
            (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro'),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
        );

        self::assertSame(
            'price_list.control_has_no_package_prices',
            $handler->handle(new SetPriceListPackagePriceCommand(self::CLIENT, $this->controlId, self::PACKAGE, 2100, 'EUR'))->error()->code,
        );
        self::assertSame(
            'price_list_package.currency_mismatch',
            $handler->handle(new SetPriceListPackagePriceCommand(self::CLIENT, $listB, self::PACKAGE, 2100, 'USD'))->error()->code,
        );

        $ok = $handler->handle(new SetPriceListPackagePriceCommand(self::CLIENT, $listB, self::PACKAGE, 2100, 'EUR'));
        self::assertTrue($ok->isOk());
        self::assertSame(2100, $this->listPackages->find($listB, self::PACKAGE)?->amountMinor);
        self::assertContains('price_list_package.set', $this->audit->actions());
    }

    private function experiment(string $name, string $factor): int
    {
        $list = PriceList::experiment(self::CLIENT, $this->groupId, $name, $factor, new DateTimeImmutable('2026-09-10T12:00:00+00:00'));
        $this->lists->save($list);
        $id = $list->id();
        self::assertNotNull($id);

        return $id;
    }

    private function createHandler(): CreatePriceListHandler
    {
        return new CreatePriceListHandler($this->lists, $this->groups, $this->audit, new SynchronousTransactions(), $this->clock);
    }
}
