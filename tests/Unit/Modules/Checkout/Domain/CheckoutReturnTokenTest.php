<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Domain;

use Gomrok\Modules\Checkout\Domain\CheckoutReturnToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckoutReturnTokenTest extends TestCase
{
    #[Test]
    public function issuesATwoPartTokenAndVerifiesItWithTheSameSecret(): void
    {
        $token = CheckoutReturnToken::issue(123, 'gomrokimo');

        self::assertSame(123, $token->checkoutAttemptId);
        $string = (string) $token;
        self::assertCount(2, explode('_', $string));
        self::assertStringStartsWith('123_', $string);
        self::assertTrue($token->verify('gomrokimo'));
    }

    #[Test]
    public function parseRoundTripsAnIssuedToken(): void
    {
        $issued = CheckoutReturnToken::issue(456, 'secret');
        $parsed = CheckoutReturnToken::parse((string) $issued);

        self::assertNotNull($parsed);
        self::assertSame(456, $parsed->checkoutAttemptId);
        self::assertSame($issued->hash, $parsed->hash);
        self::assertTrue($parsed->verify('secret'));
    }

    #[Test]
    public function verifyFailsWithTheWrongSecret(): void
    {
        $token = CheckoutReturnToken::issue(1, 'right-secret');

        self::assertFalse($token->verify('wrong-secret'));
    }

    #[Test]
    public function verifyFailsWhenTheIdWasTamperedWith(): void
    {
        $token = CheckoutReturnToken::issue(1, 'secret');
        $tampered = CheckoutReturnToken::parse('2_' . $token->hash);

        self::assertNotNull($tampered);
        self::assertFalse($tampered->verify('secret'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'no separator' => ['123abc'];
        yield 'three parts' => ['123_abc_def'];
        yield 'empty id part' => ['_abc'];
        yield 'empty hash part' => ['123_'];
        yield 'non-numeric id' => ['abc_def'];
        yield 'negative id' => ['-1_abc'];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('malformedTokens')]
    public function parseRejectsMalformedTokens(string $token): void
    {
        self::assertNull(CheckoutReturnToken::parse($token));
    }
}
