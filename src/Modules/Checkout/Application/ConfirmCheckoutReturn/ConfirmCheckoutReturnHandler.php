<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn;

use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusResult;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Checkout\Domain\CheckoutReturnToken;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * Handles the customer's browser returning from the provider's hosted page
 * (Phase 24 Q2) — the public, unauthenticated endpoint the adapter's
 * successUrl/cancelUrl (Q4's signed {@see CheckoutReturnToken}) point at.
 * The token only proves Gomrok itself generated this URL for this attempt;
 * it is never treated as proof of payment — {@see ReconcileCheckoutStatusHandler}
 * always re-verifies the real, current status against the provider's own
 * API before anything is confirmed.
 */
final readonly class ConfirmCheckoutReturnHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private ReconcileCheckoutStatusHandler $reconcile,
        private ClientDirectory $clients,
        private string $returnTokenSecret,
    ) {
    }

    public function handle(ConfirmCheckoutReturnCommand $command): Result
    {
        $token = CheckoutReturnToken::parse($command->returnToken);
        if ($token === null || !$token->verify($this->returnTokenSecret)) {
            return Result::err(DomainError::validation('checkout_return.invalid_token', 'The return token is invalid.'));
        }

        $attempt = $this->attempts->findById($token->checkoutAttemptId);
        if ($attempt === null || $attempt->hashReturnToken() !== $token->hash) {
            return Result::err(DomainError::notFound('checkout_return.attempt_not_found', 'The checkout attempt was not found.'));
        }

        $reconcileResult = $this->reconcile->handle($token->checkoutAttemptId);
        if ($reconcileResult->isErr()) {
            return $reconcileResult;
        }
        $value = $reconcileResult->value();
        \assert($value instanceof ReconcileCheckoutStatusResult);

        $redirectUrl = match (true) {
            $value->paid => $this->redirectUrlFor($value->clientId, EndpointPurpose::CheckoutSuccess),
            CheckoutAttemptStatus::from($value->status)->isExit() => $this->redirectUrlFor($value->clientId, EndpointPurpose::CheckoutCancel),
            // Still pending/requires_action: no third URL for this (Q3 only
            // defined success/cancel) — the HTTP layer falls back to a
            // plain status body.
            default => null,
        };

        return Result::ok(new ConfirmCheckoutReturnResult($value->checkoutAttemptId, $value->status, $redirectUrl));
    }

    private function redirectUrlFor(int $clientId, EndpointPurpose $purpose): ?string
    {
        return $this->clients->findActiveEndpointUrl($clientId, $purpose);
    }
}
