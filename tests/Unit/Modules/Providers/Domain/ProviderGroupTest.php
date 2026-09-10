<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\DeviceType;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderGroupTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
    }

    #[Test]
    public function newGroupIsActiveAndEmpty(): void
    {
        $group = $this->define('turkey');

        self::assertTrue($group->isActive());
        self::assertSame([], $group->countryCodes());
        self::assertSame([], $group->accounts());
        self::assertSame([], $group->purchaseTypes());
    }

    #[Test]
    public function setCountriesUppercasesAndDeduplicates(): void
    {
        $group = $this->define('eu');
        $group->setCountries(['de', 'DE', ' nl '], $this->now);

        self::assertSame(['DE', 'NL'], $group->countryCodes());
        self::assertTrue($group->servesCountry('de'));
        self::assertFalse($group->servesCountry('fr'));
    }

    #[Test]
    public function allowsMethodFailsOpenWhenNoMethodsConfigured(): void
    {
        $group = $this->define('turkey');

        self::assertTrue($group->allowsMethod(PaymentMethod::Card));

        $group->setMethods([PaymentMethod::Card], $this->now);

        self::assertTrue($group->allowsMethod(PaymentMethod::Card));
        self::assertFalse($group->allowsMethod(PaymentMethod::PayPal));
    }

    #[Test]
    public function allowsPurchaseTypeFailsClosedWhenNoneConfigured(): void
    {
        $group = $this->define('turkey');

        self::assertFalse($group->allowsPurchaseType(PurchaseType::OneTimePayment));

        $group->setPurchaseTypes([PurchaseType::OneTimePayment], $this->now);

        self::assertTrue($group->allowsPurchaseType(PurchaseType::OneTimePayment));
        self::assertFalse($group->allowsPurchaseType(PurchaseType::Subscription));
    }

    #[Test]
    public function deviceScopingMatchesNullOrExact(): void
    {
        $anyDevice = $this->define('any');
        self::assertTrue($anyDevice->appliesToDevice(null));
        self::assertTrue($anyDevice->appliesToDevice(DeviceType::Ios));

        $iosOnly = ProviderGroup::define(7, ProviderGroupSlug::of('ios'), 'iOS', false, DeviceType::Ios, null, $this->now);
        self::assertTrue($iosOnly->appliesToDevice(DeviceType::Ios));
        self::assertFalse($iosOnly->appliesToDevice(DeviceType::Android));
        self::assertFalse($iosOnly->appliesToDevice(null));
    }

    #[Test]
    public function accountsAreKeptInPriorityOrder(): void
    {
        $group = $this->define('eu');
        $group->setAccounts([
            ProviderGroupAccount::link(50, 5),
            ProviderGroupAccount::link(10, 1),
            ProviderGroupAccount::link(30, 1),
        ], $this->now);

        $ids = array_map(static fn (ProviderGroupAccount $a): int => $a->providerAccountId(), $group->accounts());

        self::assertSame([10, 30, 50], $ids);
    }

    #[Test]
    public function disableAndEnableAreIdempotent(): void
    {
        $group = $this->define('turkey');

        $group->disable($this->now);
        $group->disable($this->now);
        self::assertFalse($group->isActive());

        $group->enable($this->now);
        self::assertTrue($group->isActive());
    }

    private function define(string $slug): ProviderGroup
    {
        return ProviderGroup::define(7, ProviderGroupSlug::of($slug), ucfirst($slug), false, null, null, $this->now);
    }
}
