<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Packages\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackageCountryPurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackagePurchaseCapabilityTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function trialIsOnlyValidForSubscriptionOrRecurringAndNeedsDays(): void
    {
        self::assertNull(PackagePurchaseCapability::validate(PurchaseType::Subscription, true, 7, 1));
        self::assertNull(PackagePurchaseCapability::validate(PurchaseType::OneTimePayment, false, null, 3));

        self::assertSame(
            'package.trial_days_required',
            PackagePurchaseCapability::validate(PurchaseType::Subscription, true, null, null)?->code,
        );
        self::assertSame(
            'package.trial_not_allowed',
            PackagePurchaseCapability::validate(PurchaseType::OneTimePayment, true, 7, null)?->code,
        );
        self::assertSame(
            'package.invalid_duration',
            PackagePurchaseCapability::validate(PurchaseType::OneTimePayment, false, null, 0)?->code,
        );
    }

    #[Test]
    public function ofClearsTrialDaysWhenTrialIsOff(): void
    {
        $capability = PackagePurchaseCapability::of(PurchaseType::OneTimePayment, false, 7, 3);

        self::assertFalse($capability->hasTrial);
        self::assertNull($capability->trialDays);
        self::assertSame(3, $capability->durationMonths);
    }

    #[Test]
    public function isSellableRequiresAtLeastOneGlobalCapability(): void
    {
        $package = Package::create(7, PackageCode::of('pro'), 'Pro', null, null, $this->now);
        self::assertFalse($package->isSellable());

        $package->setPurchaseCapabilities([PackagePurchaseCapability::of(PurchaseType::OneTimePayment)], $this->now);
        self::assertTrue($package->isSellable());

        $package->disable($this->now);
        self::assertFalse($package->isSellable());
    }

    #[Test]
    public function effectiveCapabilitiesReplaceGlobalWithCountryOverride(): void
    {
        $package = Package::create(7, PackageCode::of('pro'), 'Pro', null, null, $this->now);
        $package->setPurchaseCapabilities([
            PackagePurchaseCapability::of(PurchaseType::OneTimePayment),
            PackagePurchaseCapability::of(PurchaseType::Subscription),
        ], $this->now);
        $package->setCountryPurchaseCapabilities([
            new PackageCountryPurchaseCapability('tr', PurchaseType::OneTimePayment),
        ], $this->now);

        $global = array_map(static fn (PackagePurchaseCapability $c): string => $c->purchaseType->value, $package->effectiveCapabilities(null));
        $de = array_map(static fn (PackagePurchaseCapability $c): string => $c->purchaseType->value, $package->effectiveCapabilities('DE'));
        $tr = array_map(static fn (PackagePurchaseCapability $c): string => $c->purchaseType->value, $package->effectiveCapabilities('TR'));

        self::assertSame(['one_time_payment', 'subscription'], $global);
        self::assertSame(['one_time_payment', 'subscription'], $de);
        self::assertSame(['one_time_payment'], $tr);
    }

    #[Test]
    public function setPurchaseCapabilitiesDeduplicatesByType(): void
    {
        $package = Package::create(7, PackageCode::of('pro'), 'Pro', null, null, $this->now);
        $package->setPurchaseCapabilities([
            PackagePurchaseCapability::of(PurchaseType::OneTimePayment, false, null, 1),
            PackagePurchaseCapability::of(PurchaseType::OneTimePayment, false, null, 12),
        ], $this->now);

        self::assertCount(1, $package->purchaseCapabilities());
        self::assertSame(12, $package->purchaseCapabilities()[0]->durationMonths);
    }
}
