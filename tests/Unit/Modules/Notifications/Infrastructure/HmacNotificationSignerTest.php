<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Infrastructure\HmacNotificationSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 28 Q3 (user-specified): `t=<unix_ts>,v1=<hex HMAC-SHA256 of
 * "{t}.{raw_json_body}">`.
 */
final class HmacNotificationSignerTest extends TestCase
{
    #[Test]
    public function producesTheStripeStyleHeaderFormat(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $signer = new HmacNotificationSigner();

        $header = $signer->sign('a-secret', '{"status":"paid"}', $now);

        $timestamp = $now->getTimestamp();
        $expectedSignature = hash_hmac('sha256', $timestamp . '.{"status":"paid"}', 'a-secret');
        self::assertSame("t={$timestamp},v1={$expectedSignature}", $header);
    }

    #[Test]
    public function differentSecretsProduceDifferentSignatures(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $signer = new HmacNotificationSigner();

        $a = $signer->sign('secret-a', '{}', $now);
        $b = $signer->sign('secret-b', '{}', $now);

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function differentBodiesProduceDifferentSignatures(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $signer = new HmacNotificationSigner();

        $a = $signer->sign('secret', '{"a":1}', $now);
        $b = $signer->sign('secret', '{"a":2}', $now);

        self::assertNotSame($a, $b);
    }
}
