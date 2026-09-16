<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Providers;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Providers\GroupsTabHandler;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupsTabHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const ACCOUNT_A = 101;
    private const ACCOUNT_B = 102;
    private const ACCOUNT_C = 103;

    private InMemoryProviderGroupRepository $groups;
    private GroupsTabHandler $handler;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $this->groups = new InMemoryProviderGroupRepository();

        $accounts = (new StubProviderAccountDirectory())
            ->add(self::ACCOUNT_A, self::CLIENT, 'stripe-test', 'stripe', status: 'disabled')
            ->add(self::ACCOUNT_B, self::CLIENT, 'mollie-test', 'mollie')
            ->add(self::ACCOUNT_C, self::CLIENT, 'paypal-test', 'paypal');

        $this->handler = new GroupsTabHandler($this->groups, $accounts);
    }

    #[Test]
    public function listsGroupsAndSelectsTheRequestedOne(): void
    {
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('eu'), 'EU', false, null, 'EUR', $this->now);
        $this->groups->save($group);

        $result = $this->handler->forClient(self::CLIENT, 'eu');

        self::assertCount(1, $result->groups);
        self::assertNotNull($result->selected);
        self::assertSame('eu', $result->selected->slug);
        self::assertSame('EUR', $result->selected->currency);
    }

    #[Test]
    public function skipsADisabledLinkAndAChosenAccountFallsThroughToTheNextEnabledOne(): void
    {
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('eu'), 'EU', false, null, null, $this->now);
        $group->setAccounts([
            ProviderGroupAccount::link(self::ACCOUNT_A, 0, true),
            ProviderGroupAccount::link(self::ACCOUNT_B, 1, false),
            ProviderGroupAccount::link(self::ACCOUNT_C, 2, true),
        ], $this->now);
        $this->groups->save($group);

        $result = $this->handler->forClient(self::CLIENT, 'eu');

        self::assertNotNull($result->selected);
        $rows = $result->selected->accountRows;
        self::assertCount(3, $rows);

        // A is enabled but its account is disabled — skipped.
        self::assertSame('skipped_account_disabled', $rows[0]->resolutionStatus);
        // B's link itself is disabled — skipped, regardless of account status.
        self::assertSame('skipped_link_disabled', $rows[1]->resolutionStatus);
        // C is the first genuinely eligible one — chosen.
        self::assertSame('chosen', $rows[2]->resolutionStatus);
    }

    #[Test]
    public function aSecondEligibleAccountIsAFallbackNotChosen(): void
    {
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('eu'), 'EU', false, null, null, $this->now);
        $group->setAccounts([
            ProviderGroupAccount::link(self::ACCOUNT_B, 0, true),
            ProviderGroupAccount::link(self::ACCOUNT_C, 1, true),
        ], $this->now);
        $this->groups->save($group);

        $result = $this->handler->forClient(self::CLIENT, 'eu');

        self::assertNotNull($result->selected);
        $rows = $result->selected->accountRows;
        self::assertSame('chosen', $rows[0]->resolutionStatus);
        self::assertSame('fallback', $rows[1]->resolutionStatus);
    }

    #[Test]
    public function anUnknownClientHasNoGroups(): void
    {
        $result = $this->handler->forClient(999, null);

        self::assertSame([], $result->groups);
        self::assertNull($result->selected);
    }
}
