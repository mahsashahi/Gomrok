<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Domain;

use Gomrok\Shared\Domain\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    #[Test]
    public function normalisesCodeAndReportsScale(): void
    {
        self::assertSame('USD', Currency::of('usd')->code());
        self::assertSame(2, Currency::of('USD')->minorUnitScale());
        self::assertSame(0, Currency::of('JPY')->minorUnitScale());
        self::assertSame(3, Currency::of('BHD')->minorUnitScale());
    }

    #[Test]
    public function equalityIsByCode(): void
    {
        self::assertTrue(Currency::of('EUR')->equals(Currency::of('eur')));
        self::assertFalse(Currency::of('EUR')->equals(Currency::of('USD')));
    }

    #[Test]
    public function rejectsUnknownCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Currency::of('XYZ');
    }
}
