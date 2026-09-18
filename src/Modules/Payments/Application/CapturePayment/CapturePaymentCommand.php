<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CapturePayment;

/**
 * Identifies the payment either by its origin checkout attempt (every
 * client-facing `/api/v1/payments/{id}/capture` call, `{id}` being the
 * checkout attempt id) or directly by `paymentId` (Phase 29 Q4 — a
 * subscription-renewal charge has no checkout attempt of its own). Exactly
 * one of `checkoutAttemptId`/`paymentId` should be set; `paymentId` takes
 * precedence if both are.
 */
final readonly class CapturePaymentCommand
{
    public function __construct(
        public int $clientId,
        public ?int $checkoutAttemptId = null,
        public ?int $amountMinor = null,
        public ?int $actorId = null,
        public ?int $paymentId = null,
    ) {
    }
}
