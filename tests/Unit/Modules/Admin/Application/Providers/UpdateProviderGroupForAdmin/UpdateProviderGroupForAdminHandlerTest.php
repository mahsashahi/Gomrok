<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Providers\UpdateProviderGroupForAdmin;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderGroupForAdmin\UpdateProviderGroupForAdminCommand;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderGroupForAdmin\UpdateProviderGroupForAdminHandler;
use Gomrok\Modules\Providers\Application\ChangeProviderGroupStatus\ChangeProviderGroupStatusHandler;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupHandler;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateProviderGroupForAdminHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryProviderGroupRepository $groups;
    private UpdateProviderGroupForAdminHandler $handler;
    private int $groupId;

    protected function setUp(): void
    {
        $clock = new FrozenClock('2026-09-16T12:00:00+00:00');
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();
        $reference = new InMemoryReferenceCatalog();

        $this->groups = new InMemoryProviderGroupRepository();
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('eu'), 'EU', false, null, null, $now);
        $this->groups->save($group);
        $id = $group->id();
        \assert($id !== null);
        $this->groupId = $id;

        $this->handler = new UpdateProviderGroupForAdminHandler(
            new ConfigureProviderGroupHandler($this->groups, $reference, $audit, $transactions, $clock),
            new ChangeProviderGroupStatusHandler($this->groups, $audit, $transactions, $clock),
        );
    }

    #[Test]
    public function configuresCountriesPurchaseTypesAndMethods(): void
    {
        $result = $this->handler->handle(new UpdateProviderGroupForAdminCommand(
            groupId: $this->groupId,
            name: 'EU Default',
            countries: ['DE', 'NL'],
            purchaseTypes: ['one_time_payment', 'subscription'],
            methods: ['card'],
            currencyCode: 'EUR',
            active: true,
        ));

        self::assertTrue($result->isOk());
        $group = $this->groups->findById($this->groupId);
        self::assertNotNull($group);
        self::assertSame('EU Default', $group->name());
        self::assertSame(['DE', 'NL'], $group->countryCodes());
        self::assertSame([PurchaseType::OneTimePayment, PurchaseType::Subscription], $group->purchaseTypes());
        self::assertSame('EUR', $group->currencyCode());
        self::assertTrue($group->isActive());
    }

    #[Test]
    public function disablesTheGroup(): void
    {
        $result = $this->handler->handle(new UpdateProviderGroupForAdminCommand(
            groupId: $this->groupId,
            name: 'EU',
            countries: [],
            purchaseTypes: [],
            methods: [],
            currencyCode: null,
            active: false,
        ));

        self::assertTrue($result->isOk());
        $group = $this->groups->findById($this->groupId);
        self::assertNotNull($group);
        self::assertFalse($group->isActive());
    }

    #[Test]
    public function rejectsAnUnknownCountry(): void
    {
        $result = $this->handler->handle(new UpdateProviderGroupForAdminCommand(
            groupId: $this->groupId,
            name: 'EU',
            countries: ['ZZ'],
            purchaseTypes: [],
            methods: [],
            currencyCode: null,
            active: true,
        ));

        self::assertTrue($result->isErr());
        self::assertSame('provider_group.unknown_country', $result->error()->code);
    }
}
