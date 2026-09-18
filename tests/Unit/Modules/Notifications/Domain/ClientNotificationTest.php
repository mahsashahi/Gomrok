<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientNotificationTest extends TestCase
{
    #[Test]
    public function enqueueStartsPendingAndReadyImmediately(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', 7, 'https://client.example/hook', '{"status":"paid"}', $now);

        self::assertSame(ClientNotificationStatus::Pending, $notification->status());
        self::assertSame(0, $notification->attemptCount());
        self::assertSame($now, $notification->nextAttemptAt());
        self::assertNull($notification->lastAttemptedAt());
    }

    #[Test]
    public function recordSuccessIsTerminal(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', null, 'https://client.example/hook', '{}', $now);

        $later = $now->modify('+1 minute');
        $notification->recordSuccess($later, 200, 'ok');

        self::assertSame(ClientNotificationStatus::Sent, $notification->status());
        self::assertSame(1, $notification->attemptCount());
        self::assertNull($notification->nextAttemptAt());
        self::assertSame(200, $notification->lastResponseStatus());
        self::assertSame('ok', $notification->lastResponseBody());
        self::assertNull($notification->lastError());
    }

    #[Test]
    public function recordRetryStaysPendingWithANewNextAttempt(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', null, 'https://client.example/hook', '{}', $now);

        $next = $now->modify('+1 minute');
        $notification->recordRetry($now, 500, 'server error', 'HTTP 500', $next);

        self::assertSame(ClientNotificationStatus::Pending, $notification->status());
        self::assertSame(1, $notification->attemptCount());
        self::assertSame($next, $notification->nextAttemptAt());
        self::assertSame('HTTP 500', $notification->lastError());
    }

    #[Test]
    public function recordDeadLetterIsTerminalUntilManualRetry(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', null, 'https://client.example/hook', '{}', $now);

        $notification->recordDeadLetter($now, null, null, 'connection refused');

        self::assertSame(ClientNotificationStatus::DeadLettered, $notification->status());
        self::assertSame(1, $notification->attemptCount());
        self::assertNull($notification->nextAttemptAt());
        self::assertSame('connection refused', $notification->lastError());
    }

    #[Test]
    public function retryFromDeadLetterResetsAttemptsAndIsImmediatelyReady(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', null, 'https://client.example/hook', '{}', $now);
        $notification->recordDeadLetter($now, 500, null, 'HTTP 500');

        $retryAt = $now->modify('+1 day');
        $notification->retryFromDeadLetter($retryAt);

        self::assertSame(ClientNotificationStatus::Pending, $notification->status());
        self::assertSame(0, $notification->attemptCount());
        self::assertSame($retryAt, $notification->nextAttemptAt());
    }

    #[Test]
    public function longResponseBodiesAreTruncated(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', null, 'https://client.example/hook', '{}', $now);

        $notification->recordSuccess($now, 200, str_repeat('x', 5000));

        self::assertLessThan(5000, \strlen((string) $notification->lastResponseBody()));
        self::assertStringEndsWith('…(truncated)', (string) $notification->lastResponseBody());
    }
}
