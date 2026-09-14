<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Payments\Application\CapturePayment\CapturePaymentCommand;
use Gomrok\Modules\Payments\Application\CapturePayment\CapturePaymentHandler;
use Gomrok\Modules\Payments\Application\CapturePayment\CapturePaymentResult;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/payments/{id}/capture` (Phase 24) — `{id}` is the checkout
 * attempt id. An optional `amount_minor` body field requests a partial
 * capture; omitted or `null` captures the full authorized amount.
 */
final readonly class PaymentsCaptureAction
{
    public function __construct(
        private ClientContext $context,
        private CheckoutAttemptDirectory $attempts,
        private CapturePaymentHandler $handler,
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

        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];
        $amountMinor = isset($body['amount_minor']) && is_numeric($body['amount_minor']) ? (int) $body['amount_minor'] : null;

        $result = $this->handler->handle(new CapturePaymentCommand($clientId, $id, $amountMinor));
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof CapturePaymentResult);

        $this->idempotency->setTarget('payment', $value->paymentId);

        return $this->responder->json($response, [
            'checkout_attempt_id' => $id,
            'payment_id' => $value->paymentId,
            'status' => $value->status,
            'provider_reference' => $value->providerReference,
        ]);
    }
}
