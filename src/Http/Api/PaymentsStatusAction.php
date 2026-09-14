<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusResult;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/payments/{id}/status` (Phase 24) — unlike
 * `GET /api/v1/payments/{id}` (a plain read of stored state), this actively
 * re-checks the real status with the provider when the outcome isn't
 * settled yet (CLAUDE.md: "verify payment status with the gateway API when
 * needed") via the same {@see ReconcileCheckoutStatusHandler} the public
 * return endpoint uses — an already-terminal attempt is reported as-is,
 * with no extra provider call.
 */
final readonly class PaymentsStatusAction
{
    public function __construct(
        private ClientContext $context,
        private CheckoutAttemptDirectory $attempts,
        private ReconcileCheckoutStatusHandler $reconcile,
        private JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $clientId = $this->context->clientId();
        $id = ctype_digit($args['id'] ?? '') ? (int) $args['id'] : 0;

        $attempt = $id > 0 ? $this->attempts->findById($id) : null;
        if ($attempt === null || $attempt->clientId !== $clientId) {
            return $this->responder->problem($response, DomainError::notFound('payment.not_found', "Payment {$id} was not found."));
        }

        $result = $this->reconcile->handle($id);
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof ReconcileCheckoutStatusResult);

        return $this->responder->json($response, [
            'checkout_attempt_id' => $value->checkoutAttemptId,
            'status' => $value->status,
        ]);
    }
}
