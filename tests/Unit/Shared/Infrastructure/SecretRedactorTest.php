<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Infrastructure;

use Gomrok\Shared\Infrastructure\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    #[Test]
    public function masksSensitiveKeysAtEveryDepth(): void
    {
        $redacted = SecretRedactor::redact([
            'name' => 'Stripe DE',
            'api_key' => 'sk_live_abc',
            'config' => [
                'webhook_secret' => 'whsec_123',
                'mode' => 'live',
                'nested' => ['client_secret' => 'shh', 'public' => 'ok'],
            ],
        ]);

        self::assertSame('Stripe DE', $redacted['name']);
        self::assertSame(SecretRedactor::PLACEHOLDER, $redacted['api_key']);

        $config = $redacted['config'];
        self::assertIsArray($config);
        self::assertSame(SecretRedactor::PLACEHOLDER, $config['webhook_secret']);
        self::assertSame('live', $config['mode']);

        $nested = $config['nested'];
        self::assertIsArray($nested);
        self::assertSame(SecretRedactor::PLACEHOLDER, $nested['client_secret']);
        self::assertSame('ok', $nested['public']);
    }

    #[Test]
    public function isCaseInsensitiveAndMatchesCommonVariants(): void
    {
        $redacted = SecretRedactor::redact([
            'Password' => 'x',
            'ACCESS_TOKEN' => 'y',
            'privateKey' => 'z',
            'Authorization' => 'Bearer t',
        ]);

        self::assertSame(
            [SecretRedactor::PLACEHOLDER, SecretRedactor::PLACEHOLDER, SecretRedactor::PLACEHOLDER, SecretRedactor::PLACEHOLDER],
            array_values($redacted),
        );
    }

    #[Test]
    public function leavesPlainDataUntouched(): void
    {
        $data = ['amount_minor' => 1999, 'currency' => 'EUR', 'items' => [1, 2, 3]];

        self::assertSame($data, SecretRedactor::redact($data));
    }
}
