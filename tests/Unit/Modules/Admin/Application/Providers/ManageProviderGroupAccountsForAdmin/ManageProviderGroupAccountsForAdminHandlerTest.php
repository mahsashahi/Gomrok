<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Providers\ManageProviderGroupAccountsForAdmin;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Providers\ManageProviderGroupAccountsForAdmin\ManageProviderGroupAccountsForAdminHandler;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsHandler;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManageProviderGroupAccountsForAdminHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const ACCOUNT_A = 101;
    private const ACCOUNT_B = 102;
    private const ACCOUNT_C = 103;

    private InMemoryProviderGroupRepository $groups;
    private ManageProviderGroupAccountsForAdminHandler $handler;
    private int $groupId;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-16T12:00:00+00:00');

        $this->groups = new InMemoryProviderGroupRepository();
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('eu'), 'EU', false, null, null, $now);
        $group->setAccounts([
            ProviderGroupAccount::link(self::ACCOUNT_A, 0, true),
            ProviderGroupAccount::link(self::ACCOUNT_B, 1, false),
        ], $now);
        $this->groups->save($group);
        $id = $group->id();
        \assert($id !== null);
        $this->groupId = $id;

        $accountDirectory = (new StubProviderAccountDirectory())
            ->add(self::ACCOUNT_A, self::CLIENT, 'stripe-test', 'stripe')
            ->add(self::ACCOUNT_B, self::CLIENT, 'mollie-test', 'mollie')
            ->add(self::ACCOUNT_C, self::CLIENT, 'paypal-test', 'paypal');

        $setAccounts = new SetProviderGroupAccountsHandler(
            $this->groups,
            $accountDirectory,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            $clock,
        );

        $this->handler = new ManageProviderGroupAccountsForAdminHandler($this->groups, $setAccounts);
    }

    #[Test]
    public function addAppendsAtTheEndWithoutDisturbingExistingEntries(): void
    {
        $result = $this->handler->add($this->groupId, self::ACCOUNT_C);

        self::assertTrue($result->isOk());
        $group = $this->groups->findById($this->groupId);
        self::assertNotNull($group);
        $ids = array_map(static fn (ProviderGroupAccount $a): int => $a->providerAccountId(), $group->accounts());
        self::assertSame([self::ACCOUNT_A, self::ACCOUNT_B, self::ACCOUNT_C], $ids);

        // The pre-existing entries' enabled state is untouched.
        foreach ($group->accounts() as $entry) {
            if ($entry->providerAccountId() === self::ACCOUNT_B) {
                self::assertFalse($entry->isEnabled());
            }
        }
    }

    #[Test]
    public function addIsIdempotentForAnAlreadyLinkedAccount(): void
    {
        $result = $this->handler->add($this->groupId, self::ACCOUNT_A);

        self::assertTrue($result->isOk());
        $group = $this->groups->findById($this->groupId);
        self::assertNotNull($group);
        self::assertCount(2, $group->accounts());
    }

    #[Test]
    public function removeDropsOneEntryAndKeepsTheRest(): void
    {
        $result = $this->handler->remove($this->groupId, self::ACCOUNT_A);

        self::assertTrue($result->isOk());
        $group = $this->groups->findById($this->groupId);
        self::assertNotNull($group);
        $ids = array_map(static fn (ProviderGroupAccount $a): int => $a->providerAccountId(), $group->accounts());
        self::assertSame([self::ACCOUNT_B], $ids);
    }

    #[Test]
    public function toggleFlipsOnlyTheTargetedEntry(): void
    {
        $result = $this->handler->toggle($this->groupId, self::ACCOUNT_A);

        self::assertTrue($result->isOk());
        $group = $this->groups->findById($this->groupId);
        self::assertNotNull($group);
        foreach ($group->accounts() as $entry) {
            if ($entry->providerAccountId() === self::ACCOUNT_A) {
                self::assertFalse($entry->isEnabled());
            }
            if ($entry->providerAccountId() === self::ACCOUNT_B) {
                self::assertFalse($entry->isEnabled());
            }
        }
    }

    #[Test]
    public function reorderAppliesTheGivenSequenceAndPreservesEnabledState(): void
    {
        $result = $this->handler->reorder($this->groupId, [self::ACCOUNT_B, self::ACCOUNT_A]);

        self::assertTrue($result->isOk());
        $group = $this->groups->findById($this->groupId);
        self::assertNotNull($group);
        $accounts = $group->accounts();
        self::assertSame(self::ACCOUNT_B, $accounts[0]->providerAccountId());
        self::assertSame(0, $accounts[0]->priority());
        self::assertFalse($accounts[0]->isEnabled());
        self::assertSame(self::ACCOUNT_A, $accounts[1]->providerAccountId());
        self::assertSame(1, $accounts[1]->priority());
        self::assertTrue($accounts[1]->isEnabled());
    }
}
