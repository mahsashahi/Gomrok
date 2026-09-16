<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin\UpdateProviderAccountForAdminCommand;
use Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin\UpdateProviderAccountForAdminHandler;
use Gomrok\Modules\Providers\Application\ChangeProviderAccountStatus\ChangeProviderAccountStatusHandler;
use Gomrok\Modules\Providers\Application\SetProviderAccountMarkets\SetProviderAccountMarketsHandler;
use Gomrok\Modules\Providers\Domain\EncryptedSecret;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccount;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\ProviderAccountSlug;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryProviderAccountRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdateProviderAccountForAdminHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryProviderAccountRepository $accounts;
    private UpdateProviderAccountForAdminHandler $handler;
    private int $accountId;

    protected function setUp(): void
    {
        $clock = new FrozenClock('2026-09-16T12:00:00+00:00');
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();

        $this->accounts = new InMemoryProviderAccountRepository();
        $account = ProviderAccount::register(
            self::CLIENT,
            1,
            ProviderAccountSlug::of('stripe-test'),
            'Stripe',
            ProviderAccountMode::Test,
            'pk_test_123',
            new EncryptedSecret('cipher', '1234'),
            [],
            [],
            $now,
        );
        $this->accounts->save($account);
        $id = $account->id();
        \assert($id !== null);
        $this->accountId = $id;

        $this->handler = new UpdateProviderAccountForAdminHandler(
            new SetProviderAccountMarketsHandler($this->accounts, new InMemoryReferenceCatalog(), $audit, $transactions, $clock),
            new ChangeProviderAccountStatusHandler($this->accounts, $audit, $transactions, $clock),
        );
    }

    #[Test]
    public function updatesNameMarketsAndKeepsTheAccountActive(): void
    {
        $result = $this->handler->handle(new UpdateProviderAccountForAdminCommand(
            accountId: $this->accountId,
            name: 'Stripe Live Renamed',
            countries: ['DE', 'NL'],
            methods: ['card'],
            active: true,
        ));

        self::assertTrue($result->isOk());

        $account = $this->accounts->findById($this->accountId);
        self::assertNotNull($account);
        self::assertSame('Stripe Live Renamed', $account->name());
        self::assertSame(['DE', 'NL'], $account->countryCodes());
        self::assertSame([PaymentMethod::Card], $account->methods());
        self::assertTrue($account->isActive());
    }

    #[Test]
    public function disablesTheAccount(): void
    {
        $result = $this->handler->handle(new UpdateProviderAccountForAdminCommand(
            accountId: $this->accountId,
            name: 'Stripe',
            countries: [],
            methods: [],
            active: false,
        ));

        self::assertTrue($result->isOk());
        $account = $this->accounts->findById($this->accountId);
        self::assertNotNull($account);
        self::assertFalse($account->isActive());
    }

    #[Test]
    public function rejectsAnUnknownPaymentMethod(): void
    {
        $result = $this->handler->handle(new UpdateProviderAccountForAdminCommand(
            accountId: $this->accountId,
            name: 'Stripe',
            countries: [],
            methods: ['bogus'],
            active: true,
        ));

        self::assertTrue($result->isErr());
        self::assertSame('provider_account.unknown_method', $result->error()->code);
    }
}
