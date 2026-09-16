<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Providers;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Providers\AccountsTabHandler;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccountsTabHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const ACCOUNT = 101;

    private AccountsTabHandler $handler;
    private InMemoryProviderGroupRepository $groups;

    protected function setUp(): void
    {
        $accounts = (new StubProviderAccountDirectory())
            ->add(self::ACCOUNT, self::CLIENT, 'stripe-test', 'stripe', countries: ['DE', 'AT'], methods: ['card']);

        $this->groups = new InMemoryProviderGroupRepository();

        $this->handler = new AccountsTabHandler(
            $accounts,
            new StubProviderCatalog(),
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            $this->groups,
        );
    }

    #[Test]
    public function listsAccountsAndSelectsTheRequestedOne(): void
    {
        $result = $this->handler->forClient(self::CLIENT, 'stripe-test');

        self::assertCount(1, $result->accounts);
        self::assertTrue($result->accounts[0]->selected);
        self::assertNotNull($result->selected);
        self::assertSame('stripe-test', $result->selected->slug);
        self::assertSame('Stripe', $result->selected->providerTypeName);
        self::assertSame('DE, AT', $result->selected->countriesDisplay);
        self::assertSame('Card', $result->selected->methodsDisplay);
        self::assertNotEmpty($result->selected->capabilitiesDisplay);
        self::assertNotEmpty($result->selected->purchaseTypesDisplay);
    }

    #[Test]
    public function showsWhichRoutingGroupsTheAccountBelongsTo(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('eu'), 'EU', false, null, null, $now);
        $group->setAccounts([ProviderGroupAccount::link(self::ACCOUNT, 0, true)], $now);
        $this->groups->save($group);

        $result = $this->handler->forClient(self::CLIENT, 'stripe-test');

        self::assertNotNull($result->selected);
        self::assertCount(1, $result->selected->groupMemberships);
        self::assertSame('EU', $result->selected->groupMemberships[0]->groupName);
        self::assertSame(0, $result->selected->groupMemberships[0]->priority);
        self::assertTrue($result->selected->groupMemberships[0]->isEnabled);
    }

    #[Test]
    public function anUnknownClientHasNoAccounts(): void
    {
        $result = $this->handler->forClient(999, null);

        self::assertSame([], $result->accounts);
        self::assertNull($result->selected);
    }
}
