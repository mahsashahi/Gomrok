<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * The kind of inbound provider message a {@see ProviderAccountEndpoint} verifies.
 *
 * - `webhook`  — signed asynchronous events (Stripe / Mollie / PayPal).
 * - `callback` — server-to-server notification with a shared hash key (Ziraat).
 * - `return`   — browser redirect back after payment; usually just a URL/flag,
 *   no signing secret.
 */
enum EndpointKind: string
{
    case Webhook = 'webhook';
    case Callback = 'callback';
    case Return_ = 'return';

    public function needsToken(): bool
    {
        return $this !== self::Return_;
    }
}
