<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Domain;

use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\ApiKeyToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiKeyTokenTest extends TestCase
{
    #[Test]
    public function parsesAWellFormedLiveToken(): void
    {
        $token = ApiKeyToken::parse('gk_live_0123456789abcdef.SGVsbG8td29ybGQtc2VjcmV0LTEyMw');

        self::assertNotNull($token);
        self::assertSame(ApiKeyPrefix::Live, $token->prefix);
        self::assertSame('0123456789abcdef', $token->keyId);
        self::assertSame('SGVsbG8td29ybGQtc2VjcmV0LTEyMw', $token->secret);
    }

    #[Test]
    public function parsesATestToken(): void
    {
        $token = ApiKeyToken::parse('gk_test_0123456789abcdef.abcdefghijklmnop');

        self::assertNotNull($token);
        self::assertSame(ApiKeyPrefix::Test, $token->prefix);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'no dot' => ['gk_live_0123456789abcdef'];
        yield 'bad prefix' => ['gk_prod_0123456789abcdef.abcdefghijklmnop'];
        yield 'short key id' => ['gk_live_0123.abcdefghijklmnop'];
        yield 'uppercase key id' => ['gk_live_0123456789ABCDEF.abcdefghijklmnop'];
        yield 'short secret' => ['gk_live_0123456789abcdef.tooshort'];
        yield 'empty' => [''];
        yield 'plain junk' => ['not-a-token'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function returnsNullForMalformedTokens(string $value): void
    {
        self::assertNull(ApiKeyToken::parse($value));
    }
}
