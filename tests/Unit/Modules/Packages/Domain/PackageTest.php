<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Packages\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function codeRejectsInvalidForms(): void
    {
        self::assertTrue(PackageCode::isValid('pro_monthly'));
        self::assertTrue(PackageCode::isValid('starter-2'));
        self::assertFalse(PackageCode::isValid('-pro'));
        self::assertFalse(PackageCode::isValid('Pro'));
        self::assertFalse(PackageCode::isValid('a b'));
    }

    #[Test]
    public function aNewPackageIsActiveAndAvailableEverywhere(): void
    {
        $package = $this->package();

        self::assertTrue($package->isActive());
        self::assertTrue($package->availableInCountry('DE'));
        self::assertTrue($package->availableInCurrency('USD'));
        self::assertTrue($package->availableViaMethod(PaymentMethod::PayPal));
        self::assertTrue($package->availableViaProviderAccount(999));
    }

    #[Test]
    public function availabilityIsUpperCasedDeduplicatedAndRestrictive(): void
    {
        $package = $this->package();
        $package->setAvailability(['de', 'DE', ' nl '], ['eur'], [PaymentMethod::Card, PaymentMethod::Card], [3, 3, 7], $this->now);

        self::assertSame(['DE', 'NL'], $package->countryCodes());
        self::assertSame(['EUR'], $package->currencyCodes());
        self::assertSame([3, 7], $package->providerAccountIds());

        self::assertTrue($package->availableInCountry('de'));
        self::assertFalse($package->availableInCountry('FR'));
        self::assertFalse($package->availableInCurrency('USD'));
        self::assertTrue($package->availableViaMethod(PaymentMethod::Card));
        self::assertFalse($package->availableViaMethod(PaymentMethod::PayPal));
        self::assertFalse($package->availableViaProviderAccount(1));
        self::assertTrue($package->availableViaProviderAccount(7));
    }

    #[Test]
    public function updateLeavesNullFieldsAndClearsOnRequest(): void
    {
        $package = $this->package('pro', 'Pro', 'original');

        $package->update(null, null, null, false, false, $this->now);
        self::assertSame('Pro', $package->name());
        self::assertSame('original', $package->description());

        $package->update('Pro Plus', null, ['tier' => 2], false, false, $this->now);
        self::assertSame('Pro Plus', $package->name());
        self::assertSame(['tier' => 2], $package->metadata());

        $package->update(null, null, null, true, true, $this->now);
        self::assertNull($package->description());
        self::assertNull($package->metadata());
    }

    #[Test]
    public function disableAndEnableAreIdempotent(): void
    {
        $package = $this->package();

        $package->disable($this->now);
        $package->disable($this->now);
        self::assertFalse($package->isActive());

        $package->enable($this->now);
        self::assertTrue($package->isActive());
    }

    private function package(string $code = 'starter', string $name = 'Starter', ?string $description = null): Package
    {
        return Package::create(7, PackageCode::of($code), $name, $description, null, $this->now);
    }
}
