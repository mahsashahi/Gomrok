<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn\ConfirmCheckoutReturnCommand;
use Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn\ConfirmCheckoutReturnHandler;
use Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn\ConfirmCheckoutReturnResult;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /payments/return?return_token=<id>_<hash>[&outcome=success|cancel]`
 * (Phase 24 Q2) — the public, unauthenticated endpoint the browser lands on
 * after the customer leaves the provider's hosted page. Deliberately
 * outside the `/api/v1` group: no API key exists at this point (it's the
 * customer's own browser, not a server-to-server call). `outcome` is only a
 * hint for which URL the provider used — the real status always comes from
 * re-checking the provider's own API (Q4's "not proof of payment" rule).
 */
final readonly class PaymentsReturnAction
{
    public function __construct(
        private ConfirmCheckoutReturnHandler $handler,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $token = \is_string($query['return_token'] ?? null) ? trim($query['return_token']) : '';

        if ($token === '') {
            return $this->responder->problem($response, DomainError::validation('checkout_return.missing_token', 'A "return_token" query parameter is required.'));
        }

        $result = $this->handler->handle(new ConfirmCheckoutReturnCommand($token));
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof ConfirmCheckoutReturnResult);

        if ($value->redirectUrl !== null) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', $value->redirectUrl);
        }

        // No client endpoint configured, or the outcome is still genuinely
        // undetermined (neither paid nor definitively failed) — there is no
        // third URL for "pending" (Q3 only defined success/cancel).
        return $this->responder->json($response, [
            'checkout_attempt_id' => $value->checkoutAttemptId,
            'status' => $value->status,
        ]);
    }
}
