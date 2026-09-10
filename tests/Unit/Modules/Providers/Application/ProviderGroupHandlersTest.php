<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Application;

use Gomrok\Modules\Providers\Application\ChangeProviderGroupStatus\ChangeProviderGroupStatusHandler;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupCommand;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupHandler;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupCommand;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupHandler;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupResult;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\ProviderGroupAccountInput;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsCommand;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsHandler;
use Gomrok\Shared\Domain\ErrorType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderGroupHandlersTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryProviderGroupRepository $groups;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->groups = new InMemoryProviderGroupRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-09T12:00:00+00:00');
    }

    #[Test]
    public function createDerivesASlugAndAudits(): void
    {
        $result = $this->createHandler()->handle(new CreateProviderGroupCommand(self::CLIENT, 'EU Default', isDefault: true));

        self::assertTrue($result->isOk());
        $payload = $result->value();
        self::assertInstanceOf(CreateProviderGroupResult::class, $payload);
        self::assertSame('eu-default', $payload->slug);
        self::assertContains('provider_group.created', $this->audit->actions());
    }

    #[Test]
    public function createRejectsASecondDefaultForTheSameDevice(): void
    {
        $handler = $this->createHandler();
        $handler->handle(new CreateProviderGroupCommand(self::CLIENT, 'First', isDefault: true));

        $result = $handler->handle(new CreateProviderGroupCommand(self::CLIENT, 'Second', isDefault: true));

        self::assertTrue($result->isErr());
        self::assertSame('provider_group.default_exists', $result->error()->code);
    }

    #[Test]
    public function createRejectsADisabledClient(): void
    {
        $handler = new CreateProviderGroupHandler(
            $this->groups,
            new StubClientDirectory(self::CLIENT, 'stub-client', active: false),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );

        $result = $handler->handle(new CreateProviderGroupCommand(self::CLIENT, 'EU'));

        self::assertTrue($result->isErr());
        self::assertSame(ErrorType::Forbidden, $result->error()->type);
    }

    #[Test]
    public function configureRejectsCountriesOnTheDefaultGroup(): void
    {
        $group = $this->create('Default', isDefault: true);

        $result = $this->configureHandler()->handle(new ConfigureProviderGroupCommand($group, countries: ['DE']));

        self::assertTrue($result->isErr());
        self::assertSame('provider_group.default_takes_no_countries', $result->error()->code);
    }

    #[Test]
    public function configureRejectsACountryAlreadyClaimedByAnotherGroup(): void
    {
        $germany = $this->create('Germany');
        $benelux = $this->create('Benelux');
        $this->configureHandler()->handle(new ConfigureProviderGroupCommand($germany, countries: ['DE'], purchaseTypes: ['one_time_payment']));

        $result = $this->configureHandler()->handle(new ConfigureProviderGroupCommand($benelux, countries: ['DE', 'NL']));

        self::assertTrue($result->isErr());
        self::assertSame('provider_group.country_already_grouped', $result->error()->code);
    }

    #[Test]
    public function configureRejectsAnUnknownPurchaseType(): void
    {
        $group = $this->create('Germany');

        $result = $this->configureHandler()->handle(new ConfigureProviderGroupCommand($group, countries: ['DE'], purchaseTypes: ['layaway']));

        self::assertTrue($result->isErr());
        self::assertSame('provider_group.unknown_purchase_type', $result->error()->code);
    }

    #[Test]
    public function configureSetsTheFullScope(): void
    {
        $group = $this->create('Germany');

        $result = $this->configureHandler()->handle(new ConfigureProviderGroupCommand(
            $group,
            countries: ['de'],
            purchaseTypes: ['one_time_payment', 'subscription'],
            methods: ['card'],
        ));

        self::assertTrue($result->isOk());
        $stored = $this->groups->findById($group);
        self::assertNotNull($stored);
        self::assertSame(['DE'], $stored->countryCodes());
        self::assertCount(2, $stored->purchaseTypes());
        self::assertContains('provider_group.configured', $this->audit->actions());
    }

    #[Test]
    public function setAccountsRejectsAnAccountThatIsNotTheClientsOwn(): void
    {
        $group = $this->create('Germany');
        $accounts = (new StubProviderAccountDirectory())->add(1, self::CLIENT, 'mollie', 'mollie');

        $handler = new SetProviderGroupAccountsHandler($this->groups, $accounts, $this->audit, new SynchronousTransactions(), $this->clock);
        $result = $handler->handle(new SetProviderGroupAccountsCommand($group, [new ProviderGroupAccountInput(999, 0)]));

        self::assertTrue($result->isErr());
        self::assertSame('provider_group.account_not_owned', $result->error()->code);
    }

    #[Test]
    public function setAccountsRejectsDuplicates(): void
    {
        $group = $this->create('Germany');
        $accounts = (new StubProviderAccountDirectory())->add(1, self::CLIENT, 'mollie', 'mollie');

        $handler = new SetProviderGroupAccountsHandler($this->groups, $accounts, $this->audit, new SynchronousTransactions(), $this->clock);
        $result = $handler->handle(new SetProviderGroupAccountsCommand($group, [
            new ProviderGroupAccountInput(1, 0),
            new ProviderGroupAccountInput(1, 1),
        ]));

        self::assertTrue($result->isErr());
        self::assertSame('provider_group.duplicate_account', $result->error()->code);
    }

    #[Test]
    public function setAccountsStoresThemInPriorityOrder(): void
    {
        $group = $this->create('Germany');
        $accounts = (new StubProviderAccountDirectory())
            ->add(1, self::CLIENT, 'mollie', 'mollie')
            ->add(2, self::CLIENT, 'stripe', 'stripe');

        $handler = new SetProviderGroupAccountsHandler($this->groups, $accounts, $this->audit, new SynchronousTransactions(), $this->clock);
        $handler->handle(new SetProviderGroupAccountsCommand($group, [
            new ProviderGroupAccountInput(2, 5),
            new ProviderGroupAccountInput(1, 1),
        ]));

        $stored = $this->groups->findById($group);
        self::assertNotNull($stored);
        self::assertSame([1, 2], array_map(static fn ($a): int => $a->providerAccountId(), $stored->accounts()));
    }

    #[Test]
    public function changeStatusIsIdempotent(): void
    {
        $group = $this->create('Germany');
        $handler = new ChangeProviderGroupStatusHandler($this->groups, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertTrue($handler->disable($group)->isOk());
        self::assertTrue($handler->disable($group)->isOk());

        $stored = $this->groups->findById($group);
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive());
    }

    private function create(string $name, bool $isDefault = false): int
    {
        $result = $this->createHandler()->handle(new CreateProviderGroupCommand(self::CLIENT, $name, isDefault: $isDefault));
        $payload = $result->value();
        self::assertInstanceOf(CreateProviderGroupResult::class, $payload);

        return $payload->groupId;
    }

    private function createHandler(): CreateProviderGroupHandler
    {
        return new CreateProviderGroupHandler(
            $this->groups,
            new StubClientDirectory(self::CLIENT),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }

    private function configureHandler(): ConfigureProviderGroupHandler
    {
        return new ConfigureProviderGroupHandler(
            $this->groups,
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }
}
