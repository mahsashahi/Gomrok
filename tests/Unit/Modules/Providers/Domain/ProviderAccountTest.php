<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\EncryptedSecret;
use Gomrok\Modules\Providers\Domain\EndpointKind;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccount;
use Gomrok\Modules\Providers\Domain\ProviderAccountEndpoint;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\ProviderAccountSlug;
use Gomrok\Modules\Providers\Domain\ProviderAccountStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderAccountTest extends TestCase
{
    private DateTimeImmutable $t0;

    protected function setUp(): void
    {
        $this->t0 = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
    }

    #[Test]
    public function registersActive(): void
    {
        $account = $this->register();

        self::assertTrue($account->isActive());
        self::assertSame(ProviderAccountMode::Test, $account->mode());
        self::assertSame(['DE', 'NL'], $account->countryCodes());
    }

    #[Test]
    public function changeMarketsUppercasesAndDeduplicatesCountries(): void
    {
        $account = $this->register();
        $account->changeMarkets(['de', 'DE', 'fr'], [PaymentMethod::Card, PaymentMethod::Card, PaymentMethod::Ideal], $this->t0);

        self::assertSame(['DE', 'FR'], $account->countryCodes());
        self::assertSame([PaymentMethod::Card, PaymentMethod::Ideal], $account->methods());
    }

    #[Test]
    public function disableThenEnableIsReversibleAndIdempotent(): void
    {
        $account = $this->register();

        $account->disable(5, 'rotating provider', $this->t0->modify('+1 hour'));
        self::assertSame(ProviderAccountStatus::Disabled, $account->status());
        self::assertSame(5, $account->disabledBy());

        $account->disable(9, 'again', $this->t0->modify('+2 hours'));
        self::assertSame(5, $account->disabledBy(), 'idempotent — keeps first stamp');

        $account->enable($this->t0->modify('+3 hours'));
        self::assertTrue($account->isActive());
        self::assertNull($account->disabledReason());
    }

    #[Test]
    public function addingAnEndpointDeactivatesThePriorActiveOneOfTheSameKind(): void
    {
        $account = $this->register();

        $account->addEndpoint(ProviderAccountEndpoint::create(EndpointKind::Webhook, 'whk_1', 'ct1'), $this->t0);
        $account->addEndpoint(ProviderAccountEndpoint::create(EndpointKind::Callback, 'cb_1', null), $this->t0);
        $account->addEndpoint(ProviderAccountEndpoint::create(EndpointKind::Webhook, 'whk_2', 'ct2'), $this->t0);

        self::assertCount(3, $account->endpoints());

        $active = $account->activeEndpoint(EndpointKind::Webhook);
        self::assertNotNull($active);
        self::assertSame('whk_2', $active->token());

        self::assertNotNull($account->activeEndpoint(EndpointKind::Callback));
    }

    private function register(): ProviderAccount
    {
        return ProviderAccount::register(
            clientId: 7,
            providerTypeId: 1,
            slug: ProviderAccountSlug::of('stripe-test'),
            name: 'Test',
            mode: ProviderAccountMode::Test,
            publicKey: 'pk_test_x',
            secret: new EncryptedSecret('ciphertext', 'abcd'),
            countryCodes: ['DE', 'NL'],
            methods: [PaymentMethod::Card],
            now: $this->t0,
        );
    }
}
