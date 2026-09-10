<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\ApiKeyToken;
use Gomrok\Modules\Clients\Infrastructure\RandomApiKeyGenerator;
use Gomrok\Shared\Infrastructure\RandomTokenGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RandomApiKeyGeneratorTest extends TestCase
{
    #[Test]
    public function mintsAParsableTokenAndStoresOnlyTheHash(): void
    {
        $generator = new RandomApiKeyGenerator(new RandomTokenGenerator());
        $now = new DateTimeImmutable('2026-09-08T12:00:00+00:00');

        $generated = $generator->generate(42, ApiKeyPrefix::Live, 'ci', $now);

        $parsed = ApiKeyToken::parse($generated->plaintextToken);
        self::assertNotNull($parsed);
        self::assertSame(ApiKeyPrefix::Live, $parsed->prefix);
        self::assertSame($parsed->keyId, $generated->apiKey->keyId());

        // stored hash matches the secret; the plaintext is not retained anywhere on the entity
        self::assertSame(hash('sha256', $parsed->secret), $generated->apiKey->secretHash());
        self::assertTrue($generated->apiKey->matchesSecret($parsed->secret));
        self::assertSame(substr($parsed->secret, -4), $generated->apiKey->lastFour());
        self::assertSame(42, $generated->apiKey->clientId());
    }

    #[Test]
    public function eachCallIsUnique(): void
    {
        $generator = new RandomApiKeyGenerator(new RandomTokenGenerator());
        $now = new DateTimeImmutable('2026-09-08T12:00:00+00:00');

        $a = $generator->generate(1, ApiKeyPrefix::Test, null, $now);
        $b = $generator->generate(1, ApiKeyPrefix::Test, null, $now);

        self::assertNotSame($a->apiKey->keyId(), $b->apiKey->keyId());
        self::assertNotSame($a->plaintextToken, $b->plaintextToken);
    }
}
