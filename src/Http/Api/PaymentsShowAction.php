<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/payments/{id}` (Phase 24) — `{id}` is the checkout attempt id
 * `POST /api/v1/payments` returned, addressing the whole lifecycle (pre- and
 * post-conversion) by one stable identifier: before the customer completes
 * anything there is no `payments` row yet (`Payment::create()`'s
 * precondition, Phase 20 Q1), so this reports the checkout attempt's own
 * state until one exists, then the real payment's.
 */
final readonly class PaymentsShowAction
{
    public function __construct(
        private ClientContext $context,
        private CheckoutAttemptDirectory $attempts,
        private PaymentDirectory $payments,
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

        $payment = $this->payments->findByCheckoutAttemptId($id);

        if ($payment !== null) {
            return $this->responder->json($response, [
                'checkout_attempt_id' => $attempt->id,
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'country' => $payment->country,
                'currency' => $payment->currencyCode,
                'amount_minor' => $payment->amountMinor,
                'purchase_type' => $payment->purchaseType,
                'payment_method' => $payment->paymentMethod,
                'subscription_interval' => $payment->subscriptionInterval,
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
                'created_at' => $payment->createdAt,
                'updated_at' => $payment->updatedAt,
            ]);
        }

        return $this->responder->json($response, [
            'checkout_attempt_id' => $attempt->id,
            'payment_id' => null,
            'status' => $attempt->status,
            'country' => $attempt->country,
            'currency' => $attempt->currencyCode,
            'amount_minor' => null,
            'purchase_type' => $attempt->purchaseType,
            'payment_method' => $attempt->paymentMethod,
            'subscription_interval' => $attempt->subscriptionInterval,
            'error_code' => $attempt->errorCode,
            'error_message' => $attempt->errorMessage,
            'created_at' => $attempt->createdAt,
            'updated_at' => $attempt->updatedAt,
        ]);
    }
}
