<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Payments\Application\CancelPayment\CancelPaymentCommand;
use Gomrok\Modules\Payments\Application\CancelPayment\CancelPaymentHandler;
use Gomrok\Modules\Payments\Application\CancelPayment\CancelPaymentResult;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/payments/{id}/cancel` (Phase 24) — `{id}` is the checkout
 * attempt id, matching every other `/api/v1/payments/{id}*` route.
 */
final readonly class PaymentsCancelAction
{
    public function __construct(
        private ClientContext $context,
        private CheckoutAttemptDirectory $attempts,
        private CancelPaymentHandler $handler,
        private IdempotencyContext $idempotency,
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

        $result = $this->handler->handle(new CancelPaymentCommand($clientId, $id));
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof CancelPaymentResult);

        $this->idempotency->setTarget('payment', $value->paymentId);

        return $this->responder->json($response, [
            'checkout_attempt_id' => $id,
            'payment_id' => $value->paymentId,
            'status' => $value->status,
        ]);
    }
}
