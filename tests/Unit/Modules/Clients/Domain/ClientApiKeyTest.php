<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientApiKeyTest extends TestCase
{
    private const NOW = '2026-09-08T12:00:00+00:00';

    #[Test]
    public function matchesTheSecretWithAConstantTimeCompare(): void
    {
        $key = $this->key('super-secret-value');

        self::assertTrue($key->matchesSecret('super-secret-value'));
        self::assertFalse($key->matchesSecret('wrong'));
    }

    #[Test]
    public function isUsableOnlyWhileActiveAndUnexpired(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        self::assertTrue($this->key('s')->isUsable($now));

        $expired = ClientApiKey::issue(1, 'kid', hash('sha256', 's'), ApiKeyPrefix::Live, 'xxxx', null, $now, $now->modify('-1 second'));
        self::assertFalse($expired->isUsable($now));

        $revoked = $this->key('s');
        $revoked->revoke(null, $now);
        self::assertFalse($revoked->isUsable($now));
        self::assertSame(ApiKeyStatus::Revoked, $revoked->status());
    }

    #[Test]
    public function revokeIsIdempotent(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $key = $this->key('s');

        $key->revoke(7, $now);
        $key->revoke(9, $now->modify('+1 hour'));

        self::assertSame(7, $key->revokedBy());
        self::assertEquals($now, $key->revokedAt());
    }

    private function key(string $secret): ClientApiKey
    {
        return ClientApiKey::issue(
            1,
            'kid',
            hash('sha256', $secret),
            ApiKeyPrefix::Live,
            substr($secret, -4),
            null,
            new DateTimeImmutable(self::NOW),
        );
    }
}
