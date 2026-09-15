<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/subscriptions/{id}` (Phase 26) — `{id}` is the checkout
 * attempt id `POST /api/v1/subscriptions` returned, mirroring
 * `PaymentsShowAction`'s addressing exactly: before the checkout completes
 * there is no `subscriptions` row yet, so this reports the checkout
 * attempt's own state until one exists, then the real subscription's.
 */
final readonly class SubscriptionsShowAction
{
    public function __construct(
        private ClientContext $context,
        private CheckoutAttemptDirectory $attempts,
        private SubscriptionDirectory $subscriptions,
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
            return $this->responder->problem($response, DomainError::notFound('subscription.not_found', "Subscription {$id} was not found."));
        }

        $subscription = $this->subscriptions->findByCheckoutAttemptId($id);

        if ($subscription !== null) {
            return $this->responder->json($response, [
                'checkout_attempt_id' => $attempt->id,
                'subscription_id' => $subscription->id,
                'client_user_ref' => $subscription->clientUserRef,
                'status' => $subscription->status,
                'currency' => $subscription->currencyCode,
                'amount_minor' => $subscription->amountMinor,
                'payment_method' => $subscription->paymentMethod,
                'subscription_interval' => $subscription->interval,
                'trial_ends_at' => $subscription->trialEndsAt,
                'current_period_start' => $subscription->currentPeriodStart,
                'current_period_end' => $subscription->currentPeriodEnd,
                'error_code' => $subscription->errorCode,
                'error_message' => $subscription->errorMessage,
                'created_at' => $subscription->createdAt,
                'updated_at' => $subscription->updatedAt,
            ]);
        }

        return $this->responder->json($response, [
            'checkout_attempt_id' => $attempt->id,
            'subscription_id' => null,
            'client_user_ref' => $attempt->clientUserRef,
            'status' => $attempt->status,
            'currency' => $attempt->currencyCode,
            'amount_minor' => null,
            'payment_method' => $attempt->paymentMethod,
            'subscription_interval' => $attempt->subscriptionInterval,
            'trial_ends_at' => null,
            'current_period_start' => null,
            'current_period_end' => null,
            'error_code' => $attempt->errorCode,
            'error_message' => $attempt->errorMessage,
            'created_at' => $attempt->createdAt,
            'updated_at' => $attempt->updatedAt,
        ]);
    }
}
