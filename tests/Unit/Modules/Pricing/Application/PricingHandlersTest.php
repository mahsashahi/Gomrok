<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Application\ChangePricingGroupStatus\ChangePricingGroupStatusHandler;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupCommand;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupResult;
use Gomrok\Modules\Pricing\Application\ReorderPricingGroups\ReorderPricingGroupsCommand;
use Gomrok\Modules\Pricing\Application\ReorderPricingGroups\ReorderPricingGroupsHandler;
use Gomrok\Modules\Pricing\Application\SetClientExchangeRate\SetClientExchangeRateCommand;
use Gomrok\Modules\Pricing\Application\SetClientExchangeRate\SetClientExchangeRateHandler;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceCommand;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceHandler;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageHandler;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PricingHandlersTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPricingGroupRepository $groups;
    private InMemoryPricingGroupPackageRepository $rows;
    private InMemoryDefaultPackagePriceRepository $defaults;
    private InMemoryClientExchangeRateRepository $rates;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->groups = new InMemoryPricingGroupRepository();
        $this->rows = new InMemoryPricingGroupPackageRepository();
        $this->defaults = new InMemoryDefaultPackagePriceRepository();
        $this->rates = new InMemoryClientExchangeRateRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function createRejectsASecondDefaultAndAPriorityClash(): void
    {
        $handler = $this->createHandler();
        self::assertTrue($handler->handle(new CreatePricingGroupCommand(self::CLIENT, 'Default', 'EUR', isDefault: true))->isOk());

        self::assertSame('pricing_group.default_exists', $handler->handle(new CreatePricingGroupCommand(self::CLIENT, 'Default 2', 'EUR', isDefault: true))->error()->code);

        self::assertTrue($handler->handle(new CreatePricingGroupCommand(self::CLIENT, 'DACH', 'EUR', priority: 1))->isOk());
        self::assertSame('pricing_group.priority_taken', $handler->handle(new CreatePricingGroupCommand(self::CLIENT, 'EU', 'EUR', priority: 1))->error()->code);
    }

    #[Test]
    public function createRejectsAnUnknownCurrency(): void
    {
        $result = $this->createHandler()->handle(new CreatePricingGroupCommand(self::CLIENT, 'X', 'ZZZ'));

        self::assertTrue($result->isErr());
        self::assertContains($result->error()->code, ['pricing_group.invalid_currency', 'pricing_group.unknown_currency']);
    }

    #[Test]
    public function reorderAssignsPrioritiesOneToN(): void
    {
        $handler = $this->createHandler();
        $handler->handle(new CreatePricingGroupCommand(self::CLIENT, 'Default', 'EUR', isDefault: true));
        $handler->handle(new CreatePricingGroupCommand(self::CLIENT, 'DACH', 'EUR', slug: 'dach', priority: 10));
        $handler->handle(new CreatePricingGroupCommand(self::CLIENT, 'EU', 'EUR', slug: 'eu', priority: 20));

        $reorder = new ReorderPricingGroupsHandler($this->groups, $this->audit, new SynchronousTransactions(), $this->clock);
        self::assertTrue($reorder->handle(new ReorderPricingGroupsCommand(self::CLIENT, ['eu', 'dach']))->isOk());

        self::assertSame(1, $this->groups->findByClientAndSlug(self::CLIENT, 'eu')?->priority());
        self::assertSame(2, $this->groups->findByClientAndSlug(self::CLIENT, 'dach')?->priority());

        self::assertSame('pricing_group.reorder_incomplete', $reorder->handle(new ReorderPricingGroupsCommand(self::CLIENT, ['eu']))->error()->code);
    }

    #[Test]
    public function theDefaultGroupCannotBeDisabled(): void
    {
        $created = $this->createHandler()->handle(new CreatePricingGroupCommand(self::CLIENT, 'Default', 'EUR', isDefault: true));
        $payload = $created->value();
        self::assertInstanceOf(CreatePricingGroupResult::class, $payload);

        $handler = new ChangePricingGroupStatusHandler($this->groups, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame('pricing_group.cannot_disable_default', $handler->disable($payload->groupId)->error()->code);
    }

    #[Test]
    public function setDefaultPriceValidatesCurrencyAndPackage(): void
    {
        $packages = (new StubPackageDirectory())->add(42, self::CLIENT, 'pro');
        $handler = new SetDefaultPackagePriceHandler($this->defaults, $packages, new InMemoryReferenceCatalog(), $this->audit, new SynchronousTransactions());

        self::assertSame('pricing.negative_amount', $handler->handle(new SetDefaultPackagePriceCommand(42, -1, 'EUR'))->error()->code);
        self::assertContains($handler->handle(new SetDefaultPackagePriceCommand(42, 100, 'ZZZ'))->error()->code, ['pricing.invalid_currency', 'pricing.unknown_currency']);
        self::assertSame('package.not_found', $handler->handle(new SetDefaultPackagePriceCommand(999, 100, 'EUR'))->error()->code);

        self::assertTrue($handler->handle(new SetDefaultPackagePriceCommand(42, 2900, 'eur'))->isOk());
        self::assertSame('EUR', $this->defaults->find(42)?->currencyCode);
    }

    #[Test]
    public function setExchangeRateRejectsSameCurrencyAndBadRate(): void
    {
        $handler = new SetClientExchangeRateHandler($this->rates, new StubClientDirectory(self::CLIENT), new InMemoryReferenceCatalog(), $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame('pricing.same_currency_rate', $handler->handle(new SetClientExchangeRateCommand(self::CLIENT, 'EUR', 'EUR', '1.0'))->error()->code);
        self::assertSame('pricing.invalid_rate', $handler->handle(new SetClientExchangeRateCommand(self::CLIENT, 'EUR', 'USD', '0'))->error()->code);
        self::assertTrue($handler->handle(new SetClientExchangeRateCommand(self::CLIENT, 'EUR', 'USD', '1.08'))->isOk());
    }

    #[Test]
    public function setGroupPackageEnforcesOverrideCurrency(): void
    {
        $this->createHandler()->handle(new CreatePricingGroupCommand(self::CLIENT, 'DACH', 'EUR', slug: 'dach', priority: 1));
        $groupId = $this->groups->findByClientAndSlug(self::CLIENT, 'dach')?->id();
        self::assertNotNull($groupId);

        $packages = (new StubPackageDirectory())->add(42, self::CLIENT, 'pro');
        $handler = new SetPricingGroupPackageHandler($this->rows, $this->groups, $packages, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame(
            'pricing_group_package.override_currency_mismatch',
            $handler->handle(new SetPricingGroupPackageCommand($groupId, 42, status: 'override', amountMinor: 2400, currency: 'USD'))->error()->code,
        );
        self::assertTrue($handler->handle(new SetPricingGroupPackageCommand($groupId, 42, status: 'override', amountMinor: 2400, currency: 'EUR', displayOrder: 5))->isOk());

        $row = $this->rows->find($groupId, 42);
        self::assertNotNull($row);
        self::assertSame(2400, $row->amountMinor());
        self::assertSame(5, $row->displayOrder());
        self::assertContains('pricing_group_package.set', $this->audit->actions());
    }

    private function createHandler(): CreatePricingGroupHandler
    {
        return new CreatePricingGroupHandler(
            $this->groups,
            new StubClientDirectory(self::CLIENT),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }
}
