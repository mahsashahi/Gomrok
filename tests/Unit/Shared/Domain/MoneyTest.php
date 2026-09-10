<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Domain;

use Brick\Money\Exception\MoneyMismatchException;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private Currency $usd;
    private Currency $eur;

    protected function setUp(): void
    {
        $this->usd = Currency::of('USD');
        $this->eur = Currency::of('EUR');
    }

    #[Test]
    public function minorRoundTrip(): void
    {
        self::assertSame(1234, Money::fromMinor(1234, $this->usd)->toMinor());
        self::assertSame(0, Money::zero($this->usd)->toMinor());
        self::assertSame('USD', Money::fromMinor(1, $this->usd)->currency()->code());
    }

    #[Test]
    public function addsAndSubtracts(): void
    {
        $sum = Money::fromMinor(1000, $this->usd)->plus(Money::fromMinor(250, $this->usd));
        self::assertSame(1250, $sum->toMinor());

        $diff = Money::fromMinor(1000, $this->usd)->minus(Money::fromMinor(250, $this->usd));
        self::assertSame(750, $diff->toMinor());
    }

    #[Test]
    public function addingDifferentCurrenciesIsAProgrammerError(): void
    {
        $this->expectException(MoneyMismatchException::class);
        Money::fromMinor(100, $this->usd)->plus(Money::fromMinor(100, $this->eur));
    }

    #[Test]
    public function multipliesWithBankersRounding(): void
    {
        // 10.00 * 0.125 = 1.25
        self::assertSame(125, Money::fromMinor(1000, $this->usd)->multipliedBy('0.125')->toMinor());
        // 0.125 rounds half-to-even -> 0.12
        self::assertSame(12, Money::fromMinor(25, $this->usd)->multipliedBy('0.5')->toMinor());
    }

    #[Test]
    public function percentageOfAmount(): void
    {
        self::assertSame(1250, Money::fromMinor(10000, $this->usd)->percentage('12.5')->toMinor());
        self::assertSame(1000, Money::fromMinor(10000, $this->usd)->percentage(10)->toMinor());
    }

    #[Test]
    public function ratioOfAnotherAmount(): void
    {
        self::assertSame(
            '0.250000000000',
            Money::fromMinor(2500, $this->usd)->ratioOf(Money::fromMinor(10000, $this->usd)),
        );
    }

    #[Test]
    public function allocatesWithoutLosingCents(): void
    {
        $whole = Money::fromMinor(1000, $this->usd);
        $parts = $whole->allocate(1, 1, 1);

        self::assertCount(3, $parts);
        $minors = array_map(static fn (Money $m): int => $m->toMinor(), $parts);
        self::assertSame([334, 333, 333], $minors);
        self::assertSame($whole->toMinor(), array_sum($minors));
    }

    #[Test]
    public function convertsAtACallerSuppliedRate(): void
    {
        $converted = Money::fromMinor(10000, $this->usd)->convertTo($this->eur, '0.9');

        self::assertSame('EUR', $converted->currency()->code());
        self::assertSame(9000, $converted->toMinor());
    }

    #[Test]
    public function formatsForALocale(): void
    {
        $formatted = Money::fromMinor(123450, $this->usd)->format('en_US');

        self::assertStringContainsString('1,234.50', $formatted);
    }

    #[Test]
    public function equalityIgnoresInstanceButNotCurrency(): void
    {
        self::assertTrue(Money::fromMinor(500, $this->usd)->equals(Money::fromMinor(500, $this->usd)));
        self::assertFalse(Money::fromMinor(500, $this->usd)->equals(Money::fromMinor(501, $this->usd)));
        self::assertFalse(Money::fromMinor(500, $this->usd)->equals(Money::fromMinor(500, $this->eur)));
    }

    #[Test]
    public function signPredicates(): void
    {
        self::assertTrue(Money::zero($this->usd)->isZero());
        self::assertTrue(Money::fromMinor(1, $this->usd)->isPositive());
        self::assertTrue(Money::fromMinor(-1, $this->usd)->isNegative());
    }
}
