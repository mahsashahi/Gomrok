<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn;

/**
 * `redirectUrl` is `null` when the client has no `checkout_success`/
 * `checkout_cancel` endpoint configured, or when the outcome is still
 * genuinely undetermined (neither paid nor definitively failed yet) — there
 * is no third "pending" URL (Phase 24 Q3 only defined success/cancel). The
 * HTTP layer falls back to a plain status response in either case.
 */
final readonly class ConfirmCheckoutReturnResult
{
    public function __construct(
        public int $checkoutAttemptId,
        public string $status,
        public ?string $redirectUrl,
    ) {
    }
}
