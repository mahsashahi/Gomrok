<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\SelectCheckoutProvider;

/**
 * `mode` (`live`/`test`) comes from the client's API key prefix in real usage
 * (Phase 7) — passed explicitly here since a checkout attempt can be driven
 * from the CLI before a real request context exists.
 */
final readonly class SelectCheckoutProviderCommand
{
    public function __construct(
        public int $checkoutAttemptId,
        public int $clientId,
        public string $mode,
        public ?string $deviceType = null,
        public ?int $actorId = null,
    ) {
    }
}
