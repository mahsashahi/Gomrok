<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Packages\Application;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Application\PackageCatalog;
use Gomrok\Modules\Packages\Application\ResolvedPackage;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageCatalogTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPackageRepository $packages;
    private StubProviderAccountDirectory $accounts;
    private PackageCatalog $catalog;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $this->packages = new InMemoryPackageRepository();
        $this->accounts = new StubProviderAccountDirectory();
        $this->accounts->add(1, self::CLIENT, 'stripe', 'stripe');
        $this->accounts->add(2, self::CLIENT, 'mollie', 'mollie');
        $this->accounts->add(3, self::CLIENT, 'paypal-off', 'paypal', status: 'disabled');
        $this->catalog = new PackageCatalog($this->packages, $this->accounts);
    }

    #[Test]
    public function returnsActivePackagesMatchingTheMarketContext(): void
    {
        $this->package('starter', []);                 // everywhere
        $this->package('de-only', ['DE'], ['EUR']);    // DE + EUR only
        $this->package('disabled', [], [], disabled: true);

        $de = $this->catalog->resolve(self::CLIENT, 'DE', 'EUR');
        $codes = array_map(static fn (ResolvedPackage $p): string => $p->code, $de);
        sort($codes);
        self::assertSame(['de-only', 'starter'], $codes);

        $fr = $this->catalog->resolve(self::CLIENT, 'FR', 'EUR');
        self::assertSame(['starter'], array_map(static fn (ResolvedPackage $p): string => $p->code, $fr));

        $usd = $this->catalog->resolve(self::CLIENT, 'DE', 'USD');
        self::assertSame(['starter'], array_map(static fn (ResolvedPackage $p): string => $p->code, $usd));
    }

    #[Test]
    public function filtersByPaymentMethodWhenGiven(): void
    {
        $package = $this->package('card-only', []);
        $package->setAvailability([], [], [PaymentMethod::Card], [], $this->now);
        $this->packages->save($package);

        self::assertCount(1, $this->catalog->resolve(self::CLIENT, 'DE', 'EUR', PaymentMethod::Card));
        self::assertCount(0, $this->catalog->resolve(self::CLIENT, 'DE', 'EUR', PaymentMethod::PayPal));
        self::assertCount(1, $this->catalog->resolve(self::CLIENT, 'DE', 'EUR'));
    }

    #[Test]
    public function narrowsProviderAccountsToTheClientsActiveOnesIntersectedWithThePackageSet(): void
    {
        $unrestricted = $this->package('any-provider', []);
        $restricted = $this->package('stripe-only', []);
        $restricted->setAvailability([], [], [], [1], $this->now);
        $this->packages->save($restricted);

        $resolved = $this->catalog->resolve(self::CLIENT, 'DE', 'EUR');
        $byCode = [];
        foreach ($resolved as $package) {
            $byCode[$package->code] = $package->availableProviderAccountIds;
        }

        // account 3 is disabled → never offered
        self::assertSame([1, 2], $byCode['any-provider']);
        self::assertSame([1], $byCode['stripe-only']);
    }

    /**
     * @param list<string> $countries
     * @param list<string> $currencies
     */
    private function package(string $code, array $countries, array $currencies = [], bool $disabled = false): Package
    {
        $package = Package::create(self::CLIENT, PackageCode::of($code), ucfirst($code), null, null, $this->now);
        if ($countries !== [] || $currencies !== []) {
            $package->setAvailability($countries, $currencies, [], [], $this->now);
        }
        if ($disabled) {
            $package->disable($this->now);
        }
        $this->packages->save($package);

        return $package;
    }
}
