<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PricingGroupTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function countriesAreUpperCasedAndDeduplicated(): void
    {
        $group = $this->group('dach');
        $group->setCountries(['de', 'DE', ' at '], $this->now);

        self::assertSame(['DE', 'AT'], $group->countryCodes());
        self::assertTrue($group->coversCountry('de'));
        self::assertFalse($group->coversCountry('fr'));
    }

    #[Test]
    public function deviceScopeMatchesNullOrExact(): void
    {
        $any = $this->group('any');
        self::assertTrue($any->appliesToDevice(null));
        self::assertTrue($any->appliesToDevice('ios'));

        $ios = PricingGroup::define(7, PricingGroupSlug::of('ios'), 'iOS', 1, 'ios', 'EUR', false, $this->now);
        self::assertTrue($ios->appliesToDevice('ios'));
        self::assertFalse($ios->appliesToDevice('web'));
        self::assertFalse($ios->appliesToDevice(null));
    }

    #[Test]
    public function disableEnableAreIdempotent(): void
    {
        $group = $this->group('dach');
        $group->disable($this->now);
        $group->disable($this->now);
        self::assertFalse($group->isActive());
        $group->enable($this->now);
        self::assertTrue($group->isActive());
    }

    #[Test]
    public function overrideRowNeedsAnAmountInTheGroupCurrency(): void
    {
        self::assertNull(PricingGroupPackage::validate(PricingRowStatus::Default, null, null, 'EUR'));
        self::assertSame(
            'pricing_group_package.override_amount_required',
            PricingGroupPackage::validate(PricingRowStatus::Override, null, 'EUR', 'EUR')?->code,
        );
        self::assertSame(
            'pricing_group_package.override_currency_mismatch',
            PricingGroupPackage::validate(PricingRowStatus::Override, 2400, 'USD', 'EUR')?->code,
        );
        self::assertNull(PricingGroupPackage::validate(PricingRowStatus::Override, 2400, 'eur', 'EUR'));
    }

    #[Test]
    public function nonOverrideRowsDropTheAmount(): void
    {
        $row = PricingGroupPackage::create(1, 2, PricingRowStatus::Default, 999, 'EUR', null, null, null, 0, $this->now);

        self::assertNull($row->amountMinor());
        self::assertNull($row->currencyCode());
    }

    private function group(string $slug): PricingGroup
    {
        return PricingGroup::define(7, PricingGroupSlug::of($slug), ucfirst($slug), 1, null, 'EUR', false, $this->now);
    }
}
