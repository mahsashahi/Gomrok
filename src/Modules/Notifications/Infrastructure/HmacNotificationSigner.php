<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Application\NotificationSigner;

final class HmacNotificationSigner implements NotificationSigner
{
    public function sign(string $secret, string $body, DateTimeImmutable $now): string
    {
        $timestamp = $now->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
