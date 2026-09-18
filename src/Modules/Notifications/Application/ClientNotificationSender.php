<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application;

/**
 * Performs the actual outbound HTTP call. A thin port so the delivery
 * handler's retry/backoff/dead-letter logic can be unit-tested without any
 * real network I/O (a fake implementation in tests).
 */
interface ClientNotificationSender
{
    public function send(string $url, string $body, string $signatureHeader): ClientNotificationSendOutcome;
}
