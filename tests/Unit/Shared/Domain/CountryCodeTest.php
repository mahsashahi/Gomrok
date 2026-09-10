<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Domain;

use Gomrok\Shared\Domain\CountryCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CountryCodeTest extends TestCase
{
    #[Test]
    public function normalisesAndCompares(): void
    {
        self::assertSame('DE', CountryCode::of(' de ')->value);
        self::assertTrue(CountryCode::of('TR')->equals(CountryCode::of('tr')));
        self::assertFalse(CountryCode::of('TR')->equals(CountryCode::of('NL')));
        self::assertSame('NL', (string) CountryCode::of('nl'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'three letters' => ['DEU'];
        yield 'one letter' => ['D'];
        yield 'digits' => ['12'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidCodes')]
    public function rejectsNonAlpha2(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        CountryCode::of($code);
    }
}
