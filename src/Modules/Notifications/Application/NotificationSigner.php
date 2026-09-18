<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application;

use DateTimeImmutable;

/**
 * Signs an outbound client notification body (Phase 28 Q3, user-specified) —
 * mirrors Stripe's well-known outbound-webhook scheme deliberately, so
 * Televika's team (and future clients) can implement against something
 * already familiar rather than a bespoke format.
 */
interface NotificationSigner
{
    /**
     * @return string the full `X-Gomrok-Signature` header value:
     *                `t=<unix_ts>,v1=<hex HMAC-SHA256 of "{t}.{body}">`
     */
    public function sign(string $secret, string $body, DateTimeImmutable $now): string;
}
