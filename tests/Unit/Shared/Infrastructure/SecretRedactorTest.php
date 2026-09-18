<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Infrastructure;

use Gomrok\Shared\Infrastructure\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 30A Q4 security pass — "Sensitive values must not be logged" /
 * "No sensitive provider credentials or API secrets should be displayed in
 * plain text." Extended with broader coverage this phase; the original
 * three tests below predate Phase 30A and are kept unchanged.
 */
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

    #[Test]
    public function redactsEveryNamedSensitiveKeyCaseInsensitively(): void
    {
        $sensitiveKeys = [
            'secret', 'Secret', 'client_secret', 'clientSecret',
            'password', 'Password', 'passwd', 'pwd',
            'token', 'api_key', 'apiKey', 'api-key',
            'private_key', 'privateKey',
            'authorization', 'Authorization',
            'signature', 'webhook_secret', 'webhookSecret',
        ];

        $input = [];
        foreach ($sensitiveKeys as $key) {
            $input[$key] = 'super-secret-value';
        }

        $result = SecretRedactor::redact($input);

        foreach ($sensitiveKeys as $key) {
            self::assertSame(SecretRedactor::PLACEHOLDER, $result[$key], "expected {$key} to be redacted");
        }
    }

    #[Test]
    public function leavesNonSensitiveKeysUntouched(): void
    {
        $input = ['payment_id' => 42, 'provider' => 'stripe', 'status' => 'paid', 'amount_minor' => 2900];

        $result = SecretRedactor::redact($input);

        self::assertSame($input, $result);
    }

    #[Test]
    public function redactsRecursivelyThroughNestedArrays(): void
    {
        $input = [
            'payment_id' => 42,
            'provider_config' => [
                'account' => 'acct_1',
                'secret' => 'sk_live_abc123',
                'nested' => [
                    'webhook_secret' => 'whsec_xyz',
                    'ok' => true,
                ],
            ],
        ];

        $result = SecretRedactor::redact($input);

        $providerConfig = $result['provider_config'];
        self::assertIsArray($providerConfig);
        self::assertSame('acct_1', $providerConfig['account']);
        self::assertSame(SecretRedactor::PLACEHOLDER, $providerConfig['secret']);

        $nested = $providerConfig['nested'];
        self::assertIsArray($nested);
        self::assertSame(SecretRedactor::PLACEHOLDER, $nested['webhook_secret']);
        self::assertTrue($nested['ok']);
    }

    #[Test]
    public function doesNotRedactBasedOnValueContent(): void
    {
        // The redactor matches key names only — a value that merely looks
        // secret-like under an innocuous key name is intentionally left
        // alone (matching keys is the documented, defence-in-depth design;
        // callers are still responsible for not putting secrets in
        // audit/error-log context under unrelated key names).
        $input = ['note' => 'sk_live_abc123'];

        $result = SecretRedactor::redact($input);

        self::assertSame('sk_live_abc123', $result['note']);
    }
}
