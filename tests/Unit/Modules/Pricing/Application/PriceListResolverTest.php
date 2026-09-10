<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceListPackage;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceListResolverTest extends TestCase
{
    private const GROUP = 3;
    private const PACKAGE = 42;

    private InMemoryPriceListRepository $lists;
    private InMemoryPriceListPackageRepository $listPackages;
    private PriceListResolver $resolver;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $this->lists = new InMemoryPriceListRepository();
        $this->listPackages = new InMemoryPriceListPackageRepository();
        $this->resolver = new PriceListResolver($this->lists, $this->listPackages);
    }

    #[Test]
    public function withNoControlListTheBaseIsUntouched(): void
    {
        $out = $this->resolver->apply(self::GROUP, self::PACKAGE, null, $this->base(2900));

        self::assertSame(2900, $out->amountMinor);
        self::assertNull($out->priceListId);
        self::assertSame(PriceSource::Baseline, $out->source);
    }

    #[Test]
    public function theControlListStampsButDoesNotChangeTheAmount(): void
    {
        $control = $this->saveList(PriceList::control(7, self::GROUP, $this->now));

        $out = $this->resolver->apply(self::GROUP, self::PACKAGE, null, $this->base(2900));

        self::assertSame(2900, $out->amountMinor);
        self::assertSame($control, $out->priceListId);
        self::assertSame('List A · control', $out->priceListName);
        self::assertSame(PriceSource::Baseline, $out->source);
    }

    #[Test]
    public function anEnabledExperimentAppliesItsFactor(): void
    {
        $this->saveList(PriceList::control(7, self::GROUP, $this->now));
        $listB = $this->saveList(PriceList::experiment(7, self::GROUP, 'List B', '0.9000', $this->now));

        $out = $this->resolver->apply(self::GROUP, self::PACKAGE, $listB, $this->base(2900));

        self::assertSame(2610, $out->amountMinor); // 2900 * 0.9
        self::assertSame('26.10', $out->amountDecimal);
        self::assertSame(PriceSource::PriceList, $out->source);
        self::assertSame($listB, $out->priceListId);
        self::assertSame('0.9000', $out->priceListFactor);
    }

    #[Test]
    public function anExactPackagePriceBeatsTheFactor(): void
    {
        $this->saveList(PriceList::control(7, self::GROUP, $this->now));
        $listB = $this->saveList(PriceList::experiment(7, self::GROUP, 'List B', '0.9000', $this->now));
        $this->listPackages->save(new PriceListPackage($listB, self::PACKAGE, 2100, 'EUR'));

        $out = $this->resolver->apply(self::GROUP, self::PACKAGE, $listB, $this->base(2900));

        self::assertSame(2100, $out->amountMinor);
        self::assertSame(PriceSource::PriceList, $out->source);
    }

    #[Test]
    public function aDisabledAssignedListFallsBackToControl(): void
    {
        $control = $this->saveList(PriceList::control(7, self::GROUP, $this->now));
        $listB = PriceList::experiment(7, self::GROUP, 'List B', '0.9000', $this->now);
        $listB->disable($this->now);
        $disabledId = $this->saveList($listB);

        $out = $this->resolver->apply(self::GROUP, self::PACKAGE, $disabledId, $this->base(2900));

        self::assertSame(2900, $out->amountMinor);
        self::assertSame($control, $out->priceListId);
    }

    #[Test]
    public function aListFromAnotherGroupFallsBackToControl(): void
    {
        $control = $this->saveList(PriceList::control(7, self::GROUP, $this->now));
        $foreign = $this->saveList(PriceList::experiment(7, 999, 'List B', '0.5000', $this->now));

        $out = $this->resolver->apply(self::GROUP, self::PACKAGE, $foreign, $this->base(2900));

        self::assertSame(2900, $out->amountMinor);
        self::assertSame($control, $out->priceListId);
    }

    private function base(int $amountMinor): ResolvedPrice
    {
        return new ResolvedPrice(
            self::PACKAGE,
            'pro',
            $amountMinor,
            number_format($amountMinor / 100, 2, '.', ''),
            'EUR',
            PriceSource::Baseline,
            'dach',
            false,
            'Pro',
            null,
            false,
            0,
        );
    }

    private function saveList(PriceList $list): int
    {
        $this->lists->save($list);
        $id = $list->id();
        \assert($id !== null);

        return $id;
    }
}
