<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PriceList;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceListTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function theControlListIsNeutralAndImmutable(): void
    {
        $control = PriceList::control(7, 3, $this->now);

        self::assertTrue($control->isControl());
        self::assertTrue($control->isEnabled());
        self::assertTrue($control->isNeutral());
        self::assertSame('1.0000', $control->factor());

        self::assertSame('price_list.cannot_disable_control', $control->disable($this->now)?->code);
        self::assertTrue($control->isEnabled());
        self::assertSame('price_list.control_factor_locked', $control->changeFactor('0.9000', $this->now)?->code);
        self::assertSame('1.0000', $control->factor());
    }

    #[Test]
    public function anExperimentListNormalisesAndValidatesItsFactor(): void
    {
        $list = PriceList::experiment(7, 3, ' List B ', '0.9', $this->now);

        self::assertFalse($list->isControl());
        self::assertSame('List B', $list->name());
        self::assertSame('0.9000', $list->factor());
        self::assertFalse($list->isNeutral());

        self::assertNull($list->changeFactor('1.2500', $this->now));
        self::assertSame('1.2500', $list->factor());

        self::assertSame('price_list.non_positive_factor', $list->changeFactor('0', $this->now)?->code);
        self::assertSame('price_list.invalid_factor', $list->changeFactor('1.234567', $this->now)?->code);
        self::assertSame('1.2500', $list->factor());
    }

    #[Test]
    public function anExperimentListWithFactorOneIsNeutral(): void
    {
        $list = PriceList::experiment(7, 3, 'List C', '1.0000', $this->now);

        self::assertTrue($list->isNeutral());
    }

    #[Test]
    public function disableAndEnableAreReversibleForAnExperiment(): void
    {
        $list = PriceList::experiment(7, 3, 'List B', '0.9000', $this->now);

        self::assertNull($list->disable($this->now));
        self::assertFalse($list->isEnabled());

        $list->enable($this->now);
        self::assertTrue($list->isEnabled());
    }
}
